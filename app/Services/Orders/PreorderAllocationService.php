<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Models\BookVariant;
use App\Models\Order;
use App\Models\StaffUser;
use App\Notifications\OrderReadyToShipNotification;
use App\Notifications\PreorderDelayedNotification;
use App\Notifications\PreorderReleasedNotification;
use App\Services\Telegram\OpsAlerts;
use App\Support\SafeNotifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class PreorderAllocationService
{
    public function __construct(
        private readonly OpsAlerts $opsAlerts,
    ) {}

    /**
     * Allocates stock for waiting orders in FIFO order (oldest placed_at first).
     * Called whenever stock of a variant increases (restock, adjustment, cancellation)
     * or when release date arrives.
     *
     * @return list<Order> orders that became ready to ship
     */
    public function allocateForVariant(BookVariant $variant): array
    {
        return DB::transaction(function () use ($variant) {
            $lockedVariant = BookVariant::whereKey($variant->id)->lockForUpdate()->firstOrFail();

            // Find all active waiting orders that have this variant
            $orders = Order::where('is_waiting_stock', true)
                ->whereIn('status', [OrderStatus::Pending, OrderStatus::Paid, OrderStatus::Processing])
                ->whereHas('items', fn ($q) => $q->where('book_variant_id', $lockedVariant->id))
                ->orderBy('placed_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($orders->isEmpty()) {
                return [];
            }

            // Calculate available physical stock for allocation:
            // Since stock was decremented at checkout, total physical stock available for waiting
            // demand is: current_stock + total_waiting_demand_for_variant.
            $totalWaitingForVariant = 0;
            $orderVariantDemands = [];
            foreach ($orders as $order) {
                $qty = (int) $order->items->where('book_variant_id', $lockedVariant->id)->sum('quantity');
                $totalWaitingForVariant += $qty;
                $orderVariantDemands[$order->id] = $qty;
            }

            $availableStock = $lockedVariant->stock_quantity + $totalWaitingForVariant;

            // Determine which orders have their variant requirement covered in FIFO order
            $allocatedOrders = [];
            $usedStock = 0;
            $now = Carbon::now();

            foreach ($orders as $order) {
                $demand = $orderVariantDemands[$order->id] ?? 0;
                $variantCovered = false;

                // Check pre-order release requirement
                $isPreorder = $lockedVariant->release_date !== null && $lockedVariant->release_date->isAfter($now->copy()->endOfDay());
                if (! $isPreorder) {
                    // Preorder has released or normal/backorder variant
                    if ($usedStock + $demand <= $availableStock) {
                        $usedStock += $demand;
                        $variantCovered = true;
                    }
                }

                if (! $variantCovered) {
                    // Since orders are FIFO, if this order cannot be covered, subsequent orders for this variant wait
                    continue;
                }

                // Check if ALL lines of this order are now ready
                if ($this->isOrderFullyReady($order, $lockedVariant->id)) {
                    $order->update(['is_waiting_stock' => false]);
                    $this->notifyReadyToShip($order);
                    $allocatedOrders[] = $order;
                }
            }

            return $allocatedOrders;
        });
    }

    /**
     * Checks if all items in an order are ready (released and stock available).
     */
    private function isOrderFullyReady(Order $order, ?int $justAllocatedVariantId = null): bool
    {
        $items = $order->items()->with('variant')->get();
        $now = Carbon::now();

        foreach ($items as $item) {
            $v = $item->variant;
            if (! $v) {
                continue;
            }

            // Digital items are always ready
            if ($v->format->isDigital()) {
                continue;
            }

            // If item has a future release date, not ready
            if ($item->is_preorder && $v->release_date !== null && $v->release_date->isAfter($now->copy()->endOfDay())) {
                return false;
            }

            // If it's a different variant that is waiting on stock, check if its stock is <= 0 and not allocated
            if ($v->id !== $justAllocatedVariantId && ($item->is_backorder || $item->is_preorder)) {
                // If variant stock is below 0, check its own FIFO allocation
                if ($v->stock_quantity < 0) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Releases all pre-orders whose release date has arrived (<= today).
     * Dispatches customer release emails and allocates waiting orders FIFO.
     *
     * @return int count of released variants
     */
    public function releasePreorders(): int
    {
        $today = Carbon::today()->toDateString();
        $variants = BookVariant::whereNotNull('release_date')
            ->where('release_date', '<=', $today)
            ->with(['book'])
            ->get();

        $count = 0;
        foreach ($variants as $variant) {
            // Find waiting orders containing this variant
            $orders = Order::where('is_waiting_stock', true)
                ->whereIn('status', [OrderStatus::Pending, OrderStatus::Paid, OrderStatus::Processing])
                ->whereHas('items', fn ($q) => $q->where('book_variant_id', $variant->id))
                ->with(['customer'])
                ->get();

            foreach ($orders as $order) {
                if ($order->customer) {
                    SafeNotifier::toCustomer($order->customer, new PreorderReleasedNotification($order, $variant));
                }
            }

            $this->allocateForVariant($variant);
            $count++;
        }

        return $count;
    }

    /**
     * When staff changes the release date of a pre-order variant, updates expected dates
     * and sends delay notification email to all waiting customers.
     */
    public function delayPreorder(BookVariant $variant, string $newDate, ?string $note = null): void
    {
        $orders = Order::where('is_waiting_stock', true)
            ->whereIn('status', [OrderStatus::Pending, OrderStatus::Paid, OrderStatus::Processing])
            ->whereHas('items', fn ($q) => $q->where('book_variant_id', $variant->id))
            ->with(['customer', 'items'])
            ->get();

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                if ($item->book_variant_id === $variant->id) {
                    $item->update(['expected_date' => $newDate]);
                }
            }

            $maxDate = $order->items()->max('expected_date');
            $order->update(['expected_ship_date' => $maxDate]);

            if ($order->customer) {
                SafeNotifier::toCustomer($order->customer, new PreorderDelayedNotification($order, $variant, $newDate, $note));
            }
        }
    }

    private function notifyReadyToShip(Order $order): void
    {
        // Telegram ops alert
        $this->opsAlerts->orderReadyToShip($order);

        // Staff notification
        $staff = StaffUser::all();
        if ($staff->isNotEmpty()) {
            Notification::send($staff, new OrderReadyToShipNotification($order));
        }
    }
}

<?php

namespace App\Services\Returns;

use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\StaffUser;
use App\Services\Admin\AuditLogger;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderStatusService;
use App\Support\Money;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnService
{
    /** Requests that still count against an item's returnable quantity. */
    private const COUNTED = [ReturnStatus::Requested, ReturnStatus::Approved, ReturnStatus::Refunded];

    /** A request that staff still have to finish. Only one per order at a time. */
    private const OPEN = [ReturnStatus::Requested, ReturnStatus::Approved];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly OrderStatusService $orderStatuses,
        private readonly AuditLogger $audit,
    ) {}

    public function deliveredAt(Order $order): ?CarbonInterface
    {
        return $order->statusHistory()->where('status', OrderStatus::Delivered)->latest('created_at')->value('created_at');
    }

    public function returnableUntil(Order $order): ?CarbonInterface
    {
        return $this->deliveredAt($order)?->copy()->addDays(config('shop.return_window_days'));
    }

    /** Why a new request cannot be made right now, or null if it can. */
    public function blockReason(Order $order): ?string
    {
        $until = $this->returnableUntil($order);

        return match (true) {
            $order->status !== OrderStatus::Delivered => 'Only delivered orders can be returned.',
            $until === null || $until->isPast() => 'The return window for this order has closed.',
            $order->returns()->whereIn('status', self::OPEN)->exists() => 'This order already has a return request in progress.',
            $this->returnableItems($order)->sum('returnable_quantity') === 0 => 'There is nothing left to return in this order.',
            default => null,
        };
    }

    /** Physical items of the order with how many copies can still be returned. */
    public function returnableItems(Order $order): Collection
    {
        $order->loadMissing(['items.variant.book' => fn ($q) => $q->withTrashed()]);
        $alreadyReturned = $this->countedQuantities($order);

        return $order->items
            ->filter(fn ($item) => ! $item->variant->format->isDigital())
            ->map(fn ($item) => [
                'item' => $item,
                'returnable_quantity' => max(0, $item->quantity - ($alreadyReturned[$item->id] ?? 0)),
            ])
            ->values();
    }

    /** @param array<int, array{order_item_id: int, quantity: int, reason?: string|null}> $lines */
    public function request(Customer $customer, Order $order, string $reason, array $lines): OrderReturn
    {
        return DB::transaction(function () use ($customer, $order, $reason, $lines) {
            $locked = Order::whereKey($order->id)->where('customer_id', $customer->id)->lockForUpdate()->firstOrFail();

            if ($block = $this->blockReason($locked)) {
                throw ValidationException::withMessages(['order' => $block]);
            }

            $returnable = $this->returnableItems($locked)->keyBy(fn ($row) => $row['item']->id);
            $requested = collect($lines)->groupBy('order_item_id')->map(fn ($group) => $group->sum('quantity'));

            foreach ($requested as $itemId => $quantity) {
                if (! $returnable->has($itemId)) {
                    throw ValidationException::withMessages(['items' => 'Only physical books from this order can be returned (ebooks and audiobooks cannot).']);
                }
                if ($quantity > $returnable[$itemId]['returnable_quantity']) {
                    throw ValidationException::withMessages(['items' => "You can return at most {$returnable[$itemId]['returnable_quantity']} of item {$itemId}."]);
                }
            }

            $return = OrderReturn::create([
                'order_id' => $locked->id,
                'customer_id' => $customer->id,
                'reason' => $reason,
                'status' => ReturnStatus::Requested,
                'requested_at' => now(),
            ]);

            foreach ($lines as $line) {
                $return->items()->create([
                    'order_item_id' => $line['order_item_id'],
                    'quantity' => $line['quantity'],
                    'reason' => $line['reason'] ?? null,
                ]);
            }

            return $return;
        });
    }

    /** A request nobody has acted on yet is simply removed (there is no "withdrawn" status). */
    public function withdraw(OrderReturn $return): void
    {
        DB::transaction(function () use ($return) {
            $locked = OrderReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReturnStatus::Requested) {
                throw ValidationException::withMessages(['status' => 'This return has already been handled and can no longer be withdrawn.']);
            }

            $locked->items()->delete();
            $locked->delete();
        });
    }

    public function approve(OrderReturn $return, StaffUser $staff, ?string $note): OrderReturn
    {
        return $this->resolve($return, $staff, [ReturnStatus::Requested], function (OrderReturn $locked) use ($note) {
            $locked->update(['status' => ReturnStatus::Approved, 'staff_note' => $note ?? $locked->staff_note]);
        });
    }

    /** Requested or approved returns can be rejected (for example when the books never arrive). */
    public function reject(OrderReturn $return, StaffUser $staff, string $note): OrderReturn
    {
        return $this->resolve($return, $staff, [ReturnStatus::Requested, ReturnStatus::Approved], function (OrderReturn $locked) use ($note) {
            $locked->update(['status' => ReturnStatus::Rejected, 'staff_note' => $note, 'resolved_at' => now()]);
        });
    }

    /**
     * The books have arrived and the money was paid back outside the system (cash or bank transfer).
     * Stock goes back up, the refund amount is saved, and when every returnable item of the order has
     * been refunded the order becomes `returned`.
     */
    public function refund(OrderReturn $return, StaffUser $staff, ?string $note): OrderReturn
    {
        return $this->resolve($return, $staff, [ReturnStatus::Approved], function (OrderReturn $locked) use ($staff, $note) {
            $order = Order::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $locked->load('items.orderItem.variant');

            // Calculated over all refunded returns of the order, minus what was already paid back,
            // so several partial refunds add up exactly to the full amount (no rounding drift).
            $previous = $order->returns()->where('status', ReturnStatus::Refunded)->with('items.orderItem')->get();
            $allItems = $previous->flatMap->items->concat($locked->items);
            $refund = $this->refundCents($order, $allItems) - $previous->sum(fn ($r) => Money::toCents($r->refund_amount));

            foreach ($locked->items as $item) {
                $this->inventory->restockFromReturn($item->orderItem->book_variant_id, $item->quantity, $locked, $staff);
            }

            $locked->update([
                'status' => ReturnStatus::Refunded,
                'refund_amount' => Money::format($refund),
                'staff_note' => $note ?? $locked->staff_note,
                'resolved_at' => now(),
            ]);

            if ($this->everythingReturned($order)) {
                $this->orderStatuses->markReturned($order, $staff, "All returnable items refunded (return #{$locked->id}).");
            }
        });
    }

    /** Refund for a set of return items: their price minus their share of the order's coupon discount, in cents. */
    public function refundCents(Order $order, Collection $returnItems): int
    {
        $goods = $returnItems->sum(fn ($ri) => Money::toCents($ri->orderItem->unit_price) * $ri->quantity);
        $subtotal = Money::toCents($order->subtotal);
        $discount = Money::toCents($order->discount_amount);

        return $subtotal > 0 ? $goods - intdiv($discount * $goods, $subtotal) : $goods;
    }

    private function resolve(OrderReturn $return, StaffUser $staff, array $allowedFrom, callable $change): OrderReturn
    {
        return DB::transaction(function () use ($return, $staff, $allowedFrom, $change) {
            $locked = OrderReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! in_array($from, $allowedFrom, true)) {
                throw ValidationException::withMessages(['status' => "This return is {$from->value} and cannot be changed this way."]);
            }

            $change($locked);
            $locked->update(['handled_by_staff_id' => $staff->id]);
            $this->audit->custom($staff, $locked->status->value, $locked,
                ['status' => $from->value],
                ['status' => $locked->status->value, 'refund_amount' => $locked->refund_amount, 'note' => $locked->staff_note]);

            return $locked;
        });
    }

    /** True when every physical copy in the order is in a refunded return. */
    private function everythingReturned(Order $order): bool
    {
        $refunded = DB::table('return_items')
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->where('returns.order_id', $order->id)
            ->where('returns.status', ReturnStatus::Refunded->value)
            ->groupBy('return_items.order_item_id')
            ->selectRaw('return_items.order_item_id, SUM(return_items.quantity) AS qty')
            ->pluck('qty', 'order_item_id');

        return $order->items()->with('variant')->get()
            ->filter(fn ($item) => ! $item->variant->format->isDigital())
            ->every(fn ($item) => (int) ($refunded[$item->id] ?? 0) >= $item->quantity);
    }

    /** order_item_id => copies already in a requested, approved or refunded return. */
    private function countedQuantities(Order $order): array
    {
        return DB::table('return_items')
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->where('returns.order_id', $order->id)
            ->whereIn('returns.status', array_map(fn ($s) => $s->value, self::COUNTED))
            ->groupBy('return_items.order_item_id')
            ->selectRaw('return_items.order_item_id, SUM(return_items.quantity) AS qty')
            ->pluck('qty', 'order_item_id')
            ->map(fn ($qty) => (int) $qty)
            ->all();
    }
}

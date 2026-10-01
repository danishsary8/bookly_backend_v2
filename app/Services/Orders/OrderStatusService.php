<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\StaffUser;
use App\Services\Admin\AuditLogger;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Every order status change goes through here so stock, coupons, payments and history stay consistent. */
class OrderStatusService
{
    /** Statuses staff can set by hand. `paid` comes from card payments later; `returned` from the returns flow. */
    public const STAFF_TARGETS = [OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Cancelled];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    /** Customers may cancel only while the order is still pending. */
    public function cancelByCustomer(Order $order, ?string $reason = null): Order
    {
        return $this->transition($order, OrderStatus::Cancelled, null, $reason ? "Cancelled by customer: {$reason}" : 'Cancelled by customer.', customer: true);
    }

    public function changeByStaff(Order $order, OrderStatus $to, StaffUser $staff, ?string $note = null): Order
    {
        return $this->transition($order, $to, $staff, $note);
    }

    /**
     * Called by the returns flow (inside its own transaction, with the order already locked) when every
     * returnable item has been refunded: the order becomes `returned` and its collected payment `refunded`.
     */
    public function markReturned(Order $lockedOrder, StaffUser $staff, ?string $note = null): void
    {
        if (! $lockedOrder->status->canTransitionTo(OrderStatus::Returned)) {
            throw ValidationException::withMessages(['status' => "An order cannot move from {$lockedOrder->status->value} to returned."]);
        }

        $from = $lockedOrder->status;
        $lockedOrder->payments()->where('status', PaymentStatus::Succeeded)->update(['status' => PaymentStatus::Refunded]);
        $lockedOrder->update(['status' => OrderStatus::Returned]);
        $lockedOrder->statusHistory()->create(['status' => OrderStatus::Returned, 'note' => $note, 'changed_by_staff_id' => $staff->id]);
        $this->audit->custom($staff, 'status_changed', $lockedOrder, ['status' => $from->value], ['status' => OrderStatus::Returned->value, 'note' => $note]);
    }

    private function transition(Order $order, OrderStatus $to, ?StaffUser $staff, ?string $note, bool $customer = false): Order
    {
        return DB::transaction(function () use ($order, $to, $staff, $note, $customer) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if ($customer && $from !== OrderStatus::Pending) {
                throw ValidationException::withMessages(['status' => 'This order can no longer be cancelled. Please contact support.']);
            }
            if ($staff && ! in_array($to, self::STAFF_TARGETS, true)) {
                throw ValidationException::withMessages(['status' => "Staff cannot set an order to {$to->value}."]);
            }
            if (! $from->canTransitionTo($to)) {
                throw ValidationException::withMessages(['status' => "An order cannot move from {$from->value} to {$to->value}."]);
            }

            if ($to === OrderStatus::Cancelled) {
                $this->undoOrder($locked, $staff);
            }
            if ($to === OrderStatus::Delivered && $locked->payment_method->value === 'cod') {
                // Cash on delivery: the courier collects the money on delivery.
                $locked->payments()->where('status', PaymentStatus::Pending)->update(['status' => PaymentStatus::Succeeded]);
            }

            $locked->update(['status' => $to]);
            $locked->statusHistory()->create(['status' => $to, 'note' => $note, 'changed_by_staff_id' => $staff?->id]);

            if ($staff) {
                $this->audit->custom($staff, 'status_changed', $locked, ['status' => $from->value], ['status' => $to->value, 'note' => $note]);
            }

            return $locked;
        });
    }

    /** Cancellation: stock back on the shelf, the coupon use given back, the pending payment marked failed. */
    private function undoOrder(Order $order, ?StaffUser $staff): void
    {
        $items = $order->items()->with('variant')->orderBy('book_variant_id')->get();

        foreach ($items as $item) {
            if (! $item->variant->format->isDigital()) {
                $this->inventory->restoreForCancellation($item->book_variant_id, $item->quantity, $order, $staff);
            }
        }

        if ($order->coupon_id) {
            $order->coupon()->lockForUpdate()->first()?->decrement('used_count');
        }

        $order->payments()->where('status', PaymentStatus::Pending)->update(['status' => PaymentStatus::Failed]);
    }
}

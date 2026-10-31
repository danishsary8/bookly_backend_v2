<?php

namespace App\Services\Returns;

use App\Enums\CouponType;
use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\StaffUser;
use App\Notifications\ReturnUpdatedNotification;
use App\Services\Admin\AuditLogger;
use App\Services\Inventory\InventoryService;
use App\Services\Orders\OrderStatusService;
use App\Support\Money;
use App\Support\SafeNotifier;
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

    /** Store-credit codes: uppercase letters and digits without the look-alikes 0/O and 1/I. */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

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
            $order->status !== OrderStatus::Delivered => __('messages.return_not_delivered'),
            $until === null || $until->isPast() => __('messages.return_window_closed'),
            $order->returns()->whereIn('status', self::OPEN)->exists() => __('messages.return_in_progress'),
            $this->returnableItems($order)->sum('returnable_quantity') === 0 => __('messages.return_nothing_left'),
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
        $return = DB::transaction(function () use ($customer, $order, $reason, $lines) {
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

        SafeNotifier::toCustomer($customer, new ReturnUpdatedNotification($return));

        return $return;
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
     * The books have arrived and the money goes back: in cash, by bank transfer (both outside the system) or as
     * store credit (a one-use coupon for the refund amount, locked to this customer, valid 12 months).
     * Stock goes back up, the refund record is saved, and when every returnable item of the order has
     * been refunded the order becomes `returned`.
     *
     * @param  'cash'|'bank_transfer'|'store_credit'  $method
     */
    public function refund(OrderReturn $return, StaffUser $staff, string $method, ?string $reference = null, ?string $note = null): OrderReturn
    {
        return $this->resolve($return, $staff, [ReturnStatus::Approved], function (OrderReturn $locked) use ($staff, $note, $method, $reference) {
            $order = Order::whereKey($locked->order_id)->lockForUpdate()->firstOrFail();
            $locked->load('items.orderItem.variant');

            // Calculated over all refunded returns of the order, minus what was already paid back,
            // so several partial refunds add up exactly to the full amount (no rounding drift).
            $previous = $order->returns()->where('status', ReturnStatus::Refunded)->with('items.orderItem')->get();
            $allItems = $previous->flatMap->items->concat($locked->items);
            $refund = Money::format($this->refundCents($order, $allItems) - $previous->sum(fn ($r) => Money::toCents($r->refund_amount)));

            foreach ($locked->items as $item) {
                $this->inventory->restockFromReturn($item->orderItem->book_variant_id, $item->quantity, $locked, $staff);
            }

            $coupon = $method === 'store_credit' ? $this->storeCredit($locked->customer_id, $refund) : null;

            $locked->update([
                'status' => ReturnStatus::Refunded,
                'refund_amount' => $refund,
                'refund_method' => $method,
                'refund_reference' => $method === 'bank_transfer' ? $reference : null,
                'refunded_by_staff_id' => $staff->id,
                'refunded_at' => now(),
                'store_credit_coupon_id' => $coupon?->id,
                'staff_note' => $note ?? $locked->staff_note,
                'resolved_at' => now(),
            ]);

            if ($this->everythingReturned($order)) {
                $this->orderStatuses->markReturned($order, $staff, "All returnable items refunded (return #{$locked->id}).");
            }
        });
    }

    /** A one-use fixed-amount coupon for one customer, valid until the end of the same day 12 months later (shop time). */
    private function storeCredit(int $customerId, string $amount): Coupon
    {
        do {
            $code = 'CREDIT-'.collect(range(1, 8))->map(fn () => self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)])->implode('');
        } while (Coupon::where('code', $code)->exists());

        return Coupon::create([
            'code' => $code,
            'type' => CouponType::Fixed,
            'value' => $amount,
            'customer_id' => $customerId,
            'max_uses' => 1,
            'used_count' => 0,
            'expires_at' => now(config('shop.timezone'))->addMonthsNoOverflow(12)->endOfDay()->utc(),
            'is_active' => true,
        ]);
    }

    /**
     * Refund for a set of return items, in cents (spec 4.8).
     * - Orders that saved a discount per line (new orders with an offer or coupon): each returned line gives back its
     *   price minus its OWN discount share, floor(line discount × returned / line quantity). No claw-back: books kept
     *   keep their discount even if they fall under the offer's N. The floor favours the customer by at most 1 cent per line.
     * - Other orders (older orders, all lines 0): the old rule, a share of the whole-order discount.
     */
    public function refundCents(Order $order, Collection $returnItems): int
    {
        $goods = $returnItems->sum(fn ($ri) => Money::toCents($ri->orderItem->unit_price) * $ri->quantity);

        if ($order->items()->where('discount_amount', '>', 0)->exists()) {
            // Grouped by order line, so a line returned in several parts adds up exactly to its full discount.
            $taken = $returnItems->groupBy(fn ($ri) => $ri->order_item_id)->sum(function ($group) {
                $line = $group->first()->orderItem;
                $returned = $group->sum('quantity');

                return $line->quantity > 0 ? intdiv(Money::toCents($line->discount_amount) * $returned, $line->quantity) : 0;
            });

            return $goods - $taken;
        }

        $subtotal = Money::toCents($order->subtotal);
        $discount = Money::toCents($order->discount_amount);

        return $subtotal > 0 ? $goods - intdiv($discount * $goods, $subtotal) : $goods;
    }

    private function resolve(OrderReturn $return, StaffUser $staff, array $allowedFrom, callable $change): OrderReturn
    {
        $resolved = DB::transaction(function () use ($return, $staff, $allowedFrom, $change) {
            $locked = OrderReturn::whereKey($return->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! in_array($from, $allowedFrom, true)) {
                throw ValidationException::withMessages(['status' => "This return is {$from->value} and cannot be changed this way."]);
            }

            $change($locked);
            $locked->update(['handled_by_staff_id' => $staff->id]);
            $this->audit->custom($staff, $locked->status->value, $locked,
                ['status' => $from->value],
                [
                    'status' => $locked->status->value,
                    'refund_amount' => $locked->refund_amount,
                    'refund_method' => $locked->refund_method,
                    'refund_reference' => $locked->refund_reference,
                    'store_credit_coupon_id' => $locked->store_credit_coupon_id,
                    'note' => $locked->staff_note,
                ]);

            return $locked;
        });

        // After the commit, so a mail outage can never undo or block the return step.
        SafeNotifier::toCustomer($resolved->customer, new ReturnUpdatedNotification($resolved));

        return $resolved;
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

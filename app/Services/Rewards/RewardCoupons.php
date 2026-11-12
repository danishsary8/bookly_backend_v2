<?php

namespace App\Services\Rewards;

use App\Enums\CouponType;
use App\Enums\OrderStatus;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\RewardRule;
use App\Notifications\RewardCouponNotification;
use App\Support\Money;
use App\Support\SafeNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * "Buy 2 books, get 10% off your next order" (owner 2026-11-12). The newest active reward rule decides. An order earns
 * one coupon, made when it is paid by KHQR or delivered (cash), never at checkout, so a cancelled order earns nothing.
 * The coupon is locked to the customer, one use, capped (max_discount) and ends after `valid_days`. An order paid with a
 * reward coupon does not earn another. Cancelling or returning the earning order switches an unused reward off.
 * Like every coupon it never stacks with an automatic offer (the better discount wins); the KHQR discount still applies.
 */
class RewardCoupons
{
    public function activeRule(): ?RewardRule
    {
        return RewardRule::where('is_active', true)->latest('id')->first();
    }

    /** Whether this many books / this net amount (cents) earn the rule's reward. */
    public static function qualifies(RewardRule $rule, int $books, int $netCents): bool
    {
        if ($rule->min_books === null && $rule->min_amount === null) {
            return $books > 0;
        }

        return ($rule->min_books !== null && $books >= $rule->min_books)
            || ($rule->min_amount !== null && $netCents >= Money::toCents($rule->min_amount));
    }

    /**
     * For the cart: what the next order earns, and how many more books it needs (0 = earns already).
     *
     * @return array{percent_off: string, max_discount_usd: string|null, valid_days: int, min_books: int|null, min_amount_usd: string|null, add_books: int|null, earns: bool}|null
     */
    public function preview(int $books, int $netCents): ?array
    {
        $rule = $this->activeRule();
        if ($rule === null || $books === 0) {
            return null;
        }

        return [
            'percent_off' => $rule->percent_off,
            'max_discount_usd' => $rule->max_discount,
            'valid_days' => $rule->valid_days,
            'min_books' => $rule->min_books,
            'min_amount_usd' => $rule->min_amount,
            'add_books' => $rule->min_books !== null ? max(0, $rule->min_books - $books) : null,
            'earns' => self::qualifies($rule, $books, $netCents),
        ];
    }

    /** Makes the reward for a paid or delivered order, once. Returns the coupon, or null when nothing is earned. */
    public function issueFor(Order $order): ?Coupon
    {
        $existing = Coupon::where('earned_by_order_id', $order->id)->first();
        if ($existing !== null) {
            return $existing;
        }
        if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Returned], true)) {
            return null;
        }
        if ($order->coupon_id !== null && Coupon::whereKey($order->coupon_id)->whereNotNull('earned_by_order_id')->exists()) {
            return null; // paid with a reward: no chain of rewards
        }
        $rule = $this->activeRule();
        if ($rule === null) {
            return null;
        }
        $books = (int) $order->items()->sum('quantity');
        $net = Money::toCents($order->subtotal) - Money::toCents($order->discount_amount);
        if (! self::qualifies($rule, $books, $net)) {
            return null;
        }

        try {
            $coupon = Coupon::create([
                'code' => $this->newCode(),
                'type' => CouponType::Percentage,
                'value' => $rule->percent_off,
                'max_discount' => $rule->max_discount,
                'max_uses' => 1,
                'used_count' => 0,
                'expires_at' => now()->addDays($rule->valid_days)->endOfDay(),
                'is_active' => true,
                'customer_id' => $order->customer_id,
                'earned_by_order_id' => $order->id,
                'reward_rule_id' => $rule->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            return Coupon::where('earned_by_order_id', $order->id)->first(); // made by a parallel request
        }

        SafeNotifier::toCustomer($order->customer, new RewardCouponNotification($coupon, RewardCouponNotification::EARNED));

        return $coupon;
    }

    /** The earning order was cancelled or returned: an unused reward stops working. */
    public function voidFor(Order $order): void
    {
        Coupon::where('earned_by_order_id', $order->id)->where('used_count', 0)->update(['is_active' => false]);
    }

    /** "Your coupon ends in 3 days": once per coupon, for unused, still-valid rewards. Returns how many were sent. */
    public function remindEnding(): int
    {
        $sent = 0;
        Coupon::query()
            ->whereNotNull('earned_by_order_id')
            ->where('is_active', true)
            ->where('used_count', 0)
            ->whereNull('reminded_at')
            ->whereBetween('expires_at', [now(), now()->addDays(3)])
            ->with('customer')
            ->orderBy('id')
            ->each(function (Coupon $coupon) use (&$sent) {
                $coupon->forceFill(['reminded_at' => now()])->save();
                SafeNotifier::toCustomer($coupon->customer, new RewardCouponNotification($coupon, RewardCouponNotification::ENDING));
                $sent++;
            });

        return $sent;
    }

    private function newCode(): string
    {
        do {
            $code = 'THANKS-'.strtoupper(Str::random(6));
        } while (Coupon::where('code', $code)->exists());

        return $code;
    }
}

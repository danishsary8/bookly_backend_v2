<?php

namespace App\Services\Cart;

use App\Enums\OrderStatus;
use App\Models\Coupon;
use App\Models\Customer;
use Illuminate\Validation\ValidationException;

class CouponService
{
    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * Checks a code for this customer and subtotal. Returns the coupon and the discount in cents,
     * or throws a validation error on `code`. Checkout must call this again before placing the order.
     *
     * @return array{coupon: Coupon, discount_cents: int}
     */
    public function evaluate(Customer $customer, string $code, int $subtotalCents): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['code' => $message]);

        $coupon = Coupon::where('code', self::normalize($code))->first() ?? $fail('This coupon code is not valid.');

        if ($reason = $coupon->unusableReason(number_format($subtotalCents / 100, 2, '.', ''))) {
            $fail($reason);
        }

        // One use per customer; a cancelled order gives the use back.
        $alreadyUsed = $coupon->orders()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->exists();

        if ($alreadyUsed) {
            $fail('You have already used this coupon.');
        }

        return ['coupon' => $coupon, 'discount_cents' => $coupon->discountCentsFor($subtotalCents)];
    }
}

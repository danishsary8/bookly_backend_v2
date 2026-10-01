<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Services\Cart\CartService;
use App\Services\Cart\CouponService;
use App\Services\CurrencyService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Preview a coupon against the current cart. Nothing is saved; the code is sent again at checkout. */
class CouponCheckController extends Controller
{
    public function __invoke(Request $request, CartService $carts, CouponService $coupons, CurrencyService $currency): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:50']]);
        $summary = $carts->summary($request->user());

        if ($summary['subtotal_cents'] === 0) {
            throw ValidationException::withMessages(['code' => 'Add items to your cart before using a coupon.']);
        }

        ['coupon' => $coupon, 'discount_cents' => $discount] = $coupons->evaluate($request->user(), $data['code'], $summary['subtotal_cents']);

        $subtotal = Money::format($summary['subtotal_cents']);
        $discountUsd = Money::format($discount);
        $total = Money::format($summary['subtotal_cents'] - $discount);

        return response()->json(['data' => [
            'code' => $coupon->code,
            'type' => $coupon->type->value,
            'value' => $coupon->value,
            'subtotal_usd' => $subtotal,
            'discount_usd' => $discountUsd,
            'discount_khr' => $currency->usdToKhr($discountUsd),
            'total_before_shipping_usd' => $total,
            'total_before_shipping_khr' => $currency->usdToKhr($total),
        ]]);
    }
}

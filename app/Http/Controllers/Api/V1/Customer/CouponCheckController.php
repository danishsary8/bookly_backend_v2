<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Services\Cart\CartService;
use App\Services\Cart\CouponService;
use App\Services\CurrencyService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Preview a coupon against the current cart. Nothing is saved; the code is sent again at checkout.
 * 5 codes that don't exist, in a row, lock checking for that customer for 15 minutes, so codes can't be guessed (owner, 2026-10-13).
 */
class CouponCheckController extends Controller
{
    public const MAX_WRONG = 5;

    public const LOCK_MINUTES = 15;

    public function __invoke(Request $request, CartService $carts, CouponService $coupons, CurrencyService $currency): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:50']]);
        $wrongKey = 'coupon-wrong:'.$request->user()->getKey();

        if ((int) Cache::get($wrongKey, 0) >= self::MAX_WRONG) {
            return response()->json(['message' => __('messages.coupon_locked', ['minutes' => self::LOCK_MINUTES])], 429)
                ->header('Retry-After', (string) (self::LOCK_MINUTES * 60));
        }

        $summary = $carts->summary($request->user());

        if ($summary['subtotal_cents'] === 0) {
            throw ValidationException::withMessages(['code' => 'Add items to your cart before using a coupon.']);
        }

        try {
            ['coupon' => $coupon, 'discount_cents' => $discount] = $coupons->evaluate($request->user(), $data['code'], $summary['subtotal_cents']);
        } catch (ValidationException $e) {
            // Only codes that don't exist count as guesses (not a real code that is expired or needs a bigger order);
            // the 15 minutes start again from the last wrong one.
            if (($e->errors()['code'][0] ?? null) === CouponService::NOT_FOUND) {
                Cache::put($wrongKey, (int) Cache::get($wrongKey, 0) + 1, now()->addMinutes(self::LOCK_MINUTES));
            }
            throw $e;
        }
        Cache::forget($wrongKey); // a right code clears the count

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

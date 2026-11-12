<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RewardCouponController extends Controller
{
    /**
     * The customer's reward coupons ("10% off your next order"), usable ones first, then newest. The cart offers the
     * first usable one; the account lists them all.
     */
    public function index(Request $request): JsonResponse
    {
        $coupons = Coupon::where('customer_id', $request->user()->id)
            ->whereNotNull('earned_by_order_id')
            ->with('earnedBy:id,order_number')
            ->latest('id')
            ->get()
            ->map(function (Coupon $coupon) {
                $expired = $coupon->expires_at !== null && $coupon->expires_at->isPast();
                $used = $coupon->used_count > 0;

                return [
                    'id' => $coupon->id,
                    'code' => $coupon->code,
                    'percent_off' => $coupon->value,
                    'max_discount_usd' => $coupon->max_discount,
                    'expires_at' => $coupon->expires_at?->toIso8601String(),
                    'used' => $used,
                    'expired' => $expired,
                    'is_usable' => $coupon->is_active && ! $used && ! $expired,
                    'earned_by_order' => ['id' => $coupon->earned_by_order_id, 'order_number' => $coupon->earnedBy?->order_number],
                    'created_at' => $coupon->created_at?->toIso8601String(),
                ];
            })
            ->sortByDesc('is_usable')
            ->values();

        return response()->json(['data' => $coupons]);
    }
}

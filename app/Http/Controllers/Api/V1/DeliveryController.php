<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CurrencyService;
use App\Services\Orders\DeliveryArea;
use Illuminate\Http\JsonResponse;

class DeliveryController extends Controller
{
    /**
     * Public delivery policy: free delivery threshold and per-province delivery options.
     */
    public function options(CurrencyService $currency): JsonResponse
    {
        $thresholdUsd = DeliveryArea::freeShippingThreshold();
        $freeShipping = $thresholdUsd !== null ? [
            'threshold_usd' => $thresholdUsd,
            'threshold_khr' => $currency->usdToKhr($thresholdUsd),
            'is_active' => true,
        ] : [
            'threshold_usd' => null,
            'threshold_khr' => null,
            'is_active' => false,
        ];

        $provinces = array_map(function (array $p) use ($currency) {
            return [
                'code' => $p['code'],
                'name_en' => $p['name_en'],
                'name_km' => $p['name_km'],
                'area' => $p['area'],
                'fee_usd' => $p['fee_usd'],
                'fee_khr' => $currency->usdToKhr($p['fee_usd']),
                'days_min' => $p['days_min'],
                'days_max' => $p['days_max'],
                'delivery_time_en' => $p['delivery_time_en'],
                'delivery_time_km' => $p['delivery_time_km'],
            ];
        }, DeliveryArea::allProvinces());

        return response()->json([
            'data' => [
                'free_shipping' => $freeShipping,
                'shipping_fees' => [
                    'phnom_penh_usd' => DeliveryArea::fee(DeliveryArea::PHNOM_PENH),
                    'phnom_penh_khr' => $currency->usdToKhr(DeliveryArea::fee(DeliveryArea::PHNOM_PENH)),
                    'provinces_usd' => DeliveryArea::fee(DeliveryArea::PROVINCES),
                    'provinces_khr' => $currency->usdToKhr(DeliveryArea::fee(DeliveryArea::PROVINCES)),
                ],
                'provinces' => $provinces,
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Staff\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Services\Admin\ReportsService;
use App\Services\Settings\ShopSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Store settings (owner 2026-11-16, Phase 4): delivery fees for Phnom Penh and the provinces, the free delivery
 * threshold and the monthly sales goal. Staff with catalog:view read them; admins change them (audited). Checkout
 * reads the fees through DeliveryArea, so a change applies to the next cart.
 */
class ShopSettingsController extends Controller
{
    public function __construct(private readonly ShopSettings $settings) {}

    public function show(): JsonResponse
    {
        return $this->respond();
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'shipping_fee_phnom_penh_usd' => ['sometimes', 'required', 'decimal:0,2', 'min:0', 'max:100'],
            'shipping_fee_provinces_usd' => ['sometimes', 'required', 'decimal:0,2', 'min:0', 'max:100'],
            'free_shipping_threshold_usd' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0', 'max:100000'],
            'monthly_sales_goal_usd' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0', 'max:10000000'],
        ]);
        $map = [
            'shipping_fee_phnom_penh_usd' => 'shipping_fee_phnom_penh',
            'shipping_fee_provinces_usd' => 'shipping_fee_provinces',
            'free_shipping_threshold_usd' => 'free_shipping_threshold',
            'monthly_sales_goal_usd' => 'monthly_sales_goal',
        ];
        $values = [];
        foreach ($map as $field => $key) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $v = $data[$field];
            // 0 or empty switches free delivery and the goal off; a fee of 0 means free delivery for that area.
            $off = $v === null || ($key !== 'shipping_fee_phnom_penh' && $key !== 'shipping_fee_provinces' && (float) $v === 0.0);
            $values[$key] = $off ? null : number_format((float) $v, 2, '.', '');
        }
        $before = $this->settings->all();
        $this->settings->set($values);
        AdminAuditLog::create([
            'staff_user_id' => $request->user()->id,
            'action' => 'shop_settings.updated',
            'entity_type' => 'shop_settings',
            'entity_id' => 0,
            'before_data' => array_intersect_key($before, array_flip(array_values($map))),
            'after_data' => array_intersect_key($this->settings->all(), array_flip(array_values($map))),
        ]);

        return $this->respond();
    }

    /** This month's net revenue (cash basis, like the dashboard) against the goal. Admin only. */
    public function goal(ReportsService $reports): JsonResponse
    {
        $tz = config('shop.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();
        $monthStart = $today->startOfMonth();
        $daysInMonth = $today->daysInMonth;
        $daysPassed = (int) $monthStart->diffInDays($today) + 1;
        $sales = $reports->sales($monthStart, $today->addDay(), 'day');
        $net = Money::toCents($sales['totals']['net_revenue_usd']);
        $goal = $this->settings->all()['monthly_sales_goal'];
        $goalCents = $goal === null ? null : Money::toCents($goal);
        $expected = $goalCents === null ? null : intdiv($goalCents * $daysPassed, $daysInMonth);
        $daysLeft = $daysInMonth - $daysPassed;

        return response()->json(['data' => [
            'month' => $monthStart->format('Y-m'),
            'days_in_month' => $daysInMonth,
            'days_passed' => $daysPassed,
            'net_revenue_usd' => Money::format($net),
            'net_revenue_khr' => $sales['totals']['net_revenue_khr'],
            'goal_usd' => $goalCents === null ? null : Money::format($goalCents),
            'percent' => $goalCents ? round($net / $goalCents * 100, 1) : null,
            'expected_by_today_usd' => $expected === null ? null : Money::format($expected),
            'on_track' => $expected === null ? null : $net >= $expected,
            'needed_per_day_usd' => $goalCents === null ? null : Money::format($daysLeft > 0 ? (int) ceil(max(0, $goalCents - $net) / $daysLeft) : max(0, $goalCents - $net)),
        ]]);
    }

    private function respond(): JsonResponse
    {
        $all = $this->settings->all();

        return response()->json(['data' => [
            'shipping_fee_phnom_penh_usd' => $all['shipping_fee_phnom_penh'] ?? '1.50',
            'shipping_fee_provinces_usd' => $all['shipping_fee_provinces'] ?? '3.00',
            'free_shipping_threshold_usd' => $all['free_shipping_threshold'],
            'monthly_sales_goal_usd' => $all['monthly_sales_goal'],
            'province_rates' => config('shop.shipping_fees.province_rates'),
        ]]);
    }
}

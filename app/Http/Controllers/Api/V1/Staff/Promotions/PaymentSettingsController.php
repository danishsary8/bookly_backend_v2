<?php

namespace App\Http\Controllers\Api\V1\Staff\Promotions;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Services\Settings\ShopSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payment settings (owner 2026-11-12): the KHQR discount (percent off the books) and the most a cash on delivery
 * order may cost. Null switches either off. Staff with catalog:view see them; only admins change them.
 */
class PaymentSettingsController extends Controller
{
    public function __construct(private readonly ShopSettings $settings) {}

    public function show(): JsonResponse
    {
        return $this->respond();
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'khqr_discount_percent' => ['sometimes', 'nullable', 'decimal:0,2', 'min:0', 'max:20'],
            'cod_max_order_usd' => ['sometimes', 'nullable', 'decimal:0,2', 'min:1', 'max:100000'],
        ]);
        $before = $this->settings->all();
        $values = [];
        if (array_key_exists('khqr_discount_percent', $data)) {
            $values['khqr_discount_percent'] = $data['khqr_discount_percent'] === null || (float) $data['khqr_discount_percent'] === 0.0
                ? null : number_format((float) $data['khqr_discount_percent'], 2, '.', '');
        }
        if (array_key_exists('cod_max_order_usd', $data)) {
            $values['cod_max_order'] = $data['cod_max_order_usd'] === null ? null : number_format((float) $data['cod_max_order_usd'], 2, '.', '');
        }
        $this->settings->set($values);
        AdminAuditLog::create([
            'staff_user_id' => $request->user()->id,
            'action' => 'shop_settings.updated',
            'entity_type' => 'shop_settings',
            'entity_id' => 0, // one settings record for the whole shop
            'before_data' => $before,
            'after_data' => $this->settings->all(),
        ]);

        return $this->respond();
    }

    private function respond(): JsonResponse
    {
        $all = $this->settings->all();

        return response()->json(['data' => [
            'khqr_discount_percent' => $all['khqr_discount_percent'],
            'cod_max_order_usd' => $all['cod_max_order'],
            'khqr_enabled' => (bool) config('payments.khqr.enabled'),
        ]]);
    }
}

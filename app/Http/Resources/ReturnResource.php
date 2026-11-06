<?php

namespace App\Http\Resources;

use App\Services\Returns\ReturnService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $allocation = $this->refund_amount === null ? app(ReturnService::class)->refundAllocation($this->order, $this->items) : ['total' => Money::toCents($this->refund_amount), 'credit' => (int) $this->credit_refund_cents];

        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_number' => $this->order?->order_number,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'items' => $this->items->map(fn ($ri) => [
                'id' => $ri->id,
                'order_item_id' => $ri->order_item_id,
                'title' => $ri->orderItem->bookTitle(),
                'format' => $ri->orderItem->bookFormat()?->value,
                'quantity' => $ri->quantity,
                'unit_price_usd' => $ri->orderItem->unit_price,
                'reason' => $ri->reason,
            ])->values(),
            // Before the refund this is an estimate; once refunded it is the amount actually paid back.
            'refund_amount_usd' => $this->refund_amount
                ?? Money::format($allocation['total']),
            'is_refund_final' => $this->refund_amount !== null,
            'credit_refund_usd' => Money::format($allocation['credit']),
            'external_refund_usd' => Money::format($allocation['total'] - $allocation['credit']),
            // cash | bank_transfer | store_credit; null before the refund and for refunds made before it was recorded.
            'refund_method' => $this->refund_method,
            'refund_reference' => $this->refund_reference,
            'refunded_at' => $this->refunded_at,
            'store_credit' => $this->storeCreditCoupon ? [
                'code' => $this->storeCreditCoupon->code,
                'amount_usd' => $this->storeCreditCoupon->value,
                'expires_at' => $this->storeCreditCoupon->expires_at,
            ] : null,
            'staff_note' => $this->staff_note,
            'requested_at' => $this->requested_at,
            'resolved_at' => $this->resolved_at,
            'can_withdraw' => $this->status->value === 'requested',
        ];
    }
}

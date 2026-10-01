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
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'order_number' => $this->order?->order_number,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'items' => $this->items->map(fn ($ri) => [
                'id' => $ri->id,
                'order_item_id' => $ri->order_item_id,
                'title' => $ri->orderItem->variant?->book?->title,
                'format' => $ri->orderItem->variant?->format->value,
                'quantity' => $ri->quantity,
                'unit_price_usd' => $ri->orderItem->unit_price,
                'reason' => $ri->reason,
            ])->values(),
            // Before the refund this is an estimate; once refunded it is the amount actually paid back.
            'refund_amount_usd' => $this->refund_amount
                ?? Money::format(app(ReturnService::class)->refundCents($this->order, $this->items)),
            'is_refund_final' => $this->refund_amount !== null,
            'staff_note' => $this->staff_note,
            'requested_at' => $this->requested_at,
            'resolved_at' => $this->resolved_at,
            'can_withdraw' => $this->status->value === 'requested',
        ];
    }
}

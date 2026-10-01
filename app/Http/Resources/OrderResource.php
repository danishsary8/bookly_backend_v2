<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Services\CurrencyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'payment_method' => $this->payment_method->value,
            'payment_status' => $this->whenLoaded('payments', fn () => $this->payments->sortByDesc('id')->first()?->status->value),
            'placed_at' => $this->placed_at,
            'item_count' => $this->whenLoaded('items', fn () => $this->items->sum('quantity')),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'book_variant_id' => $item->book_variant_id,
                'book_id' => $item->variant?->book_id,
                'title' => $item->variant?->book?->title,
                'format' => $item->variant?->format->value,
                'quantity' => $item->quantity,
                'unit_price_usd' => $item->unit_price,
                'subtotal_usd' => $item->subtotal,
            ])->values()),
            'subtotal_usd' => $this->subtotal,
            'discount_usd' => $this->discount_amount,
            'shipping_fee_usd' => $this->shipping_fee,
            'tax_usd' => $this->tax_amount,
            'total_usd' => $this->total_amount,
            'total_khr' => app(CurrencyService::class)->usdToKhr($this->total_amount),
            'coupon_code' => $this->whenLoaded('coupon', fn () => $this->coupon?->code),
            'shipping_address' => [
                'recipient_name' => $this->shipping_recipient_name,
                'phone' => $this->shipping_phone,
                'address_line1' => $this->shipping_address_line1,
                'address_line2' => $this->shipping_address_line2,
                'city' => $this->shipping_city,
                'state' => $this->shipping_state,
                'postal_code' => $this->shipping_postal_code,
                'country' => $this->shipping_country,
            ],
            'can_cancel' => $this->status === OrderStatus::Pending,
            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($h) => [
                'status' => $h->status->value,
                'note' => $h->note,
                'created_at' => $h->created_at,
            ])->values()),
        ];
    }
}

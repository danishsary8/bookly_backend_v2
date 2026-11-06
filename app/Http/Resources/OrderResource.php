<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Services\CurrencyService;
use App\Services\Orders\DeliveryArea;
use App\Services\Payments\KhqrPaymentService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'free_gift' => $this->free_gift,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'payment_method' => $this->payment_method->value,
            // A paid QR wins over newer QR rows that were closed as failed (KHQR: one row per QR).
            'payment_status' => $this->whenLoaded('payments', fn () => $this->credit_used_cents > 0 && $this->externalPaymentAmount() === '0.00' ? ($this->status === OrderStatus::Cancelled || $this->status === OrderStatus::Returned ? 'refunded' : 'succeeded') : ($this->payments->firstWhere('status', PaymentStatus::Succeeded) ?? $this->payments->sortByDesc('id')->first())?->status->value),
            // KHQR orders waiting for payment: when the time to pay ends (the shop shows "Pay now" until then).
            'payment_due_at' => $this->payment_method === PaymentMethod::Khqr && $this->status === OrderStatus::Pending
                ? app(KhqrPaymentService::class)->deadline($this->resource)->toIso8601String()
                : null,
            'placed_at' => $this->placed_at,
            'is_waiting_stock' => (bool) $this->is_waiting_stock,
            'expected_ship_date' => $this->expected_ship_date?->format('Y-m-d'),
            'item_count' => $this->whenLoaded('items', fn () => $this->items->sum('quantity')),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'book_variant_id' => $item->book_variant_id,
                'book_id' => $item->variant?->book_id,
                'title' => $item->bookTitle(),
                'format' => $item->bookFormat()?->value,
                'quantity' => $item->quantity,
                'unit_price_usd' => $item->unit_price,
                'subtotal_usd' => $item->subtotal,
                'discount_usd' => $item->discount_amount ?? '0.00', // this line's share of the order discount
                'is_preorder' => (bool) $item->is_preorder,
                'is_backorder' => (bool) $item->is_backorder,
                'expected_date' => $item->expected_date?->format('Y-m-d'),
            ])->values()),
            'subtotal_usd' => $this->subtotal,
            'discount_usd' => $this->discount_amount,
            'shipping_fee_usd' => $this->shipping_fee,
            'tax_usd' => $this->tax_amount,
            'total_usd' => $this->total_amount,
            'credit_used_usd' => Money::format($this->credit_used_cents),
            'payment_due_usd' => $this->externalPaymentAmount(),
            'total_khr' => app(CurrencyService::class)->usdToKhr($this->total_amount),
            'coupon_code' => $this->whenLoaded('coupon', fn () => $this->coupon?->code),
            // The automatic offer used, from the copy saved at order time (stays when the offer is edited or deleted).
            'offer' => $this->offer_name === null ? null : [
                'id' => $this->offer_id,
                'name' => $this->offer_name,
                'min_quantity' => $this->offer_min_quantity,
                'percent_off' => $this->offer_percent_off,
            ],
            'shipping_address' => [
                'recipient_name' => $this->shipping_recipient_name,
                'phone' => $this->shipping_phone,
                'address_line1' => $this->shipping_address_line1,
                'address_line2' => $this->shipping_address_line2,
                'city' => $this->shipping_city,
                'state' => $this->shipping_state,
                'postal_code' => $this->shipping_postal_code,
                'country' => $this->shipping_country,
                // The customer's map pin, if they set one: the courier opens it in Google Maps.
                'latitude' => $this->shipping_latitude !== null ? (float) $this->shipping_latitude : null,
                'longitude' => $this->shipping_longitude !== null ? (float) $this->shipping_longitude : null,
            ],
            'can_cancel' => $this->status === OrderStatus::Pending || ($this->is_waiting_stock && in_array($this->status, [OrderStatus::Paid, OrderStatus::Processing], true)),
            'delivery_note' => $this->delivery_note,
            'is_gift' => (bool) $this->is_gift,
            'gift_message' => $this->gift_message,
            'is_free_shipping' => (float) $this->shipping_fee === 0.0 && (float) $this->subtotal > 0,
            'estimated_delivery' => DeliveryArea::estimate($this->placed_at, $this->shipping_city, $this->shipping_state),
            'status_history' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($h) => [
                'status' => $h->status->value,
                'note' => $h->note === 'Order placed.' ? __('messages.order_placed_note') : $h->note,
                'created_at' => $h->created_at,
            ])->values()),
        ];
    }
}

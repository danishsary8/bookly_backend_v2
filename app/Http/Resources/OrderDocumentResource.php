<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReturnStatus;
use App\Models\Order;
use App\Services\KhrRateHistory;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything an invoice or packing slip prints for one order: the lines with SKU/ISBN and authors, the totals,
 * refunds, what the courier collects, and KHR at the rate in force when the order was placed (so a reprint never
 * changes). Load with Order::documentRelations().
 *
 * @mixin Order
 */
class OrderDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $rate = app(KhrRateHistory::class)->at($this->placed_at);
        $khr = fn (string $usd) => $rate ? Money::convert($usd, (string) $rate->rate) : null;

        // The courier collects cash only while the order is still on its way and nothing was paid or refunded yet
        // (a returned order's payment is `refunded`, a delivered one's `succeeded`).
        $payment = $this->payments->sortByDesc('id')->first()?->status;
        $open = ! in_array($this->status, [OrderStatus::Cancelled, OrderStatus::Delivered, OrderStatus::Returned], true);
        $collect = $this->payment_method === PaymentMethod::Cod && $open && in_array($payment, [null, PaymentStatus::Pending], true)
            ? (string) $this->total_amount : '0.00';
        $refunded = Money::format($this->returns
            ->filter(fn ($r) => $r->status === ReturnStatus::Refunded)
            ->sum(fn ($r) => Money::toCents($r->refund_amount)));

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'placed_at' => $this->placed_at,
            'delivered_at' => $this->statusHistory->firstWhere('status', OrderStatus::Delivered)?->created_at,
            'delivery_note' => $this->delivery_note,
            'payment_method' => $this->payment_method->value,
            'payment_status' => $this->payments->sortByDesc('id')->first()?->status->value,
            'customer' => [
                'name' => $this->customer?->name,
                'email' => $this->customer?->email,
                'phone' => $this->customer?->phone,
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
            ],
            'items' => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'title' => $item->bookTitle(),
                'authors' => $item->bookAuthors(),
                'format' => $item->bookFormat()?->value,
                'is_digital' => (bool) $item->bookFormat()?->isDigital(),
                'sku' => $item->bookSku(),
                'isbn' => $item->bookIsbn(),
                'quantity' => $item->quantity,
                'unit_price_usd' => $item->unit_price,
                'subtotal_usd' => $item->subtotal,
            ])->values(),
            'subtotal_usd' => $this->subtotal,
            'discount_usd' => $this->discount_amount,
            'coupon_code' => $this->coupon?->code,
            'shipping_fee_usd' => $this->shipping_fee,
            'tax_usd' => $this->tax_amount,
            'total_usd' => $this->total_amount,
            'refunded_usd' => $refunded,
            'amount_to_collect_usd' => $collect,
            'khr' => $rate ? [
                'rate' => $rate->rate,
                'rate_effective_at' => $rate->effective_at,
                'total' => $khr((string) $this->total_amount),
                'amount_to_collect' => $khr($collect),
            ] : null,
        ];
    }
}

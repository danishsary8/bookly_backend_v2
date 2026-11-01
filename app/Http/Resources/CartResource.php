<?php

namespace App\Http\Resources;

use App\Services\CurrencyService;
use App\Services\Offers\CartPricing;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps CartService::summary(). The older fields keep their meaning (subtotal_usd is before any discount);
 * `pricing` and each item's `in_offer` describe the automatic offer (docs/superpowers/specs/2026-10-31-offers-design.md 6.1).
 */
class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = app(CurrencyService::class);
        $subtotal = Money::format($this->resource['subtotal_cents']);
        $pricing = app(CartPricing::class)->forSummary($this->resource);

        return [
            'id' => $this->resource['cart']->id,
            'items' => $this->resource['lines']->map(function (array $line) use ($currency, $pricing) {
                $item = $line['item'];
                $variant = $item->variant;
                $total = Money::format($line['line_total_cents']);

                return [
                    'id' => $item->id,
                    'book_variant_id' => $variant->id,
                    'book' => ['id' => $variant->book_id, 'title' => $variant->book?->title],
                    'format' => $variant->format->value,
                    'cover_image_url' => $variant->cover_image_url,
                    'quantity' => $item->quantity,
                    'unit_price_usd' => $variant->price_usd,
                    'unit_price_khr' => $currency->usdToKhr($variant->price_usd),
                    'line_total_usd' => $total,
                    'line_total_khr' => $currency->usdToKhr($total),
                    'issues' => $line['issues'],
                    'in_offer' => $pricing['in_offer'][$item->id] ?? false,
                ];
            })->values(),
            'item_count' => $this->resource['item_count'],
            'subtotal_usd' => $subtotal,
            'subtotal_khr' => $currency->usdToKhr($subtotal),
            'can_checkout' => $this->resource['can_checkout'],
            'pricing' => $this->pricing($pricing, $currency),
        ];
    }

    private function pricing(array $pricing, CurrencyService $currency): array
    {
        $nudge = $pricing['nudge'];

        return [
            ...OfferPayload::money($pricing['subtotal_cents'], 'subtotal', $currency),
            ...OfferPayload::money($pricing['discount_cents'], 'discount', $currency),
            ...OfferPayload::money($pricing['subtotal_cents'] - $pricing['discount_cents'], 'total_before_shipping', $currency),
            'offer' => OfferPayload::offer($pricing['offer'], $currency),
            'nudge' => $nudge === null ? null : [
                'offer_id' => $nudge['offer']->id,
                'add_quantity' => $nudge['add_quantity'],
                'percent_off' => $nudge['offer']->percentOff,
                'target_label' => $nudge['offer']->targetLabel,
            ],
            'free_shipping' => OfferPayload::freeShipping($pricing['free_shipping'], $currency),
        ];
    }
}

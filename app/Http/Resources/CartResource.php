<?php

namespace App\Http\Resources;

use App\Services\CurrencyService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Wraps CartService::summary(). */
class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currency = app(CurrencyService::class);
        $subtotal = Money::format($this->resource['subtotal_cents']);

        return [
            'id' => $this->resource['cart']->id,
            'items' => $this->resource['lines']->map(function (array $line) use ($currency) {
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
                ];
            })->values(),
            'item_count' => $this->resource['item_count'],
            'subtotal_usd' => $subtotal,
            'subtotal_khr' => $currency->usdToKhr($subtotal),
            'can_checkout' => $this->resource['can_checkout'],
        ];
    }
}

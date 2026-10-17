<?php

namespace App\Http\Resources;

use App\Services\CurrencyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public book card for lists. BookDetailResource adds the full detail fields. */
class BookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $variants = $this->activeVariants;
        $priceFrom = $this->min_price ?? $variants->min('price_usd');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'language' => $this->language,
            'publish_date' => $this->publish_date?->toDateString(),
            'authors' => $this->whenLoaded('authors', fn () => $this->authors->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values()),
            'cover_image_url' => $variants->firstWhere('cover_image_url', '!=', null)?->cover_image_url,
            'price_from_usd' => $priceFrom !== null ? number_format((float) $priceFrom, 2, '.', '') : null,
            'price_from_khr' => app(CurrencyService::class)->usdToKhr($priceFrom),
            'formats' => $variants->map(fn ($v) => $v->format->value)->values(),
            'in_stock' => $variants->contains(fn ($v) => $v->isAvailable()),
            'rating_avg' => $this->rating_avg !== null ? round((float) $this->rating_avg, 1) : null,
            'review_count' => (int) ($this->review_count ?? 0),
            'wishlist_status' => $this->whenPivotLoaded('wishlists', fn () => $this->pivot->status ?? 'want_to_read'),
            'wishlist_saved_at' => $this->whenPivotLoaded('wishlists', fn () => $this->pivot->created_at?->toIso8601String()),
        ];
    }
}

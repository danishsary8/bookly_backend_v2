<?php

namespace App\Http\Resources;

use App\Services\CurrencyService;
use App\Services\Offers\OfferService;
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
            'age_min' => $this->age_min,
            'age_max' => $this->age_max,
            'school_grade' => $this->school_grade,
            'level' => $this->level,
            'publish_date' => $this->publish_date?->toDateString(),
            'authors' => $this->whenLoaded('authors', fn () => $this->authors->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values()),
            'cover_image_url' => $variants->firstWhere('cover_image_url', '!=', null)?->cover_image_url,
            'price_from_usd' => $priceFrom !== null ? number_format((float) $priceFrom, 2, '.', '') : null,
            'price_from_khr' => app(CurrencyService::class)->usdToKhr($priceFrom),
            'formats' => $variants->map(fn ($v) => $v->format->value)->values(),
            'in_stock' => $variants->contains(fn ($v) => $v->isAvailable()),
            'rating_avg' => $this->rating_avg !== null ? round((float) $this->rating_avg, 1) : null,
            'review_count' => (int) ($this->review_count ?? 0),
            // The best live automatic offer for this book ("Buy 2, save 15%"), or null.
            'offer' => app(OfferService::class)->forBook($this->resource),
            'series_order' => $this->series_order,
            'is_owned' => $this->when(isset($this->is_owned), (bool) $this->is_owned),
            'is_in_cart' => $this->when(isset($this->is_in_cart), (bool) $this->is_in_cart),
            'wishlist_status' => $this->whenPivotLoaded('wishlists', fn () => $this->pivot->status ?? 'want_to_read'),
            'wishlist_saved_at' => $this->whenPivotLoaded('wishlists', fn () => $this->pivot->created_at?->toIso8601String()),
            // Lowest price when it was saved (null for older saves): the wishlist shows "Now …, was …" when it fell.
            'wishlist_saved_price_usd' => $this->whenPivotLoaded('wishlists', fn () => $this->pivot->saved_price_usd !== null ? number_format((float) $this->pivot->saved_price_usd, 2, '.', '') : null),
        ];
    }
}

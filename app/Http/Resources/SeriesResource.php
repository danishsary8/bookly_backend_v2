<?php

namespace App\Http\Resources;

use App\Services\CurrencyService;
use App\Services\Offers\OfferService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeriesResource extends JsonResource
{
    /**
     * The live set offer for this series (owner 2026-11-12): percent, free delivery, end date, and what the whole set
     * costs before and after, from each volume's lowest price. Null when there is none.
     */
    private function setOffer(): ?array
    {
        $offer = app(OfferService::class)->setOfferForSeries((int) $this->id);
        if ($offer === null) {
            return null;
        }
        $currency = app(CurrencyService::class);
        $full = (int) $this->books->sum(fn ($book) => Money::toCents((string) ($book->min_price ?? '0')));
        $saving = (int) $this->books->sum(fn ($book) => intdiv(Money::toCents((string) ($book->min_price ?? '0')) * $offer->percentBasisPoints, 10000));

        return [
            'id' => $offer->id,
            'percent_off' => $offer->percentOff,
            'free_delivery' => $offer->freeDelivery,
            'ends_at' => $offer->endsAtIso,
            'volumes' => $this->books->count(),
            ...OfferPayload::money($full, 'full_price', $currency),
            ...OfferPayload::money($full - $saving, 'set_price', $currency),
            ...OfferPayload::money($saving, 'saving', $currency),
        ];
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'books_count' => $this->books_count ?? ($this->relationLoaded('books') ? $this->books->count() : null),
            'total_volumes' => $this->whenLoaded('books', fn () => $this->books->count()),
            'owned_count' => $this->whenLoaded('books', fn () => $this->books->where('is_owned', true)->count()),
            'in_cart_count' => $this->whenLoaded('books', fn () => $this->books->where('is_in_cart', true)->count()),
            'set_offer' => $this->whenLoaded('books', fn () => $this->setOffer()),
            'books' => BookResource::collection($this->whenLoaded('books')),
        ];
    }
}

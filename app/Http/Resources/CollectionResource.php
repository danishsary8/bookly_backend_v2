<?php

namespace App\Http\Resources;

use App\Models\BookCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A live seasonal collection for the shop: both languages (the shop picks; Khmer falls back to English) and its books. */
class CollectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BookCollection $c */
        $c = $this->resource;

        return [
            'id' => $c->id,
            'slug' => $c->slug,
            'title_en' => $c->title_en,
            'title_km' => $c->title_km,
            'description_en' => $c->description_en,
            'description_km' => $c->description_km,
            'ends_at' => $c->ends_at?->toIso8601ZuluString(),
            'books_count' => (int) ($c->visible_books_count ?? 0),
            'books' => BookResource::collection($c->getRelation('cardBooks')),
        ];
    }
}

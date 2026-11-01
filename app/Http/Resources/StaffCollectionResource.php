<?php

namespace App\Http\Resources;

use App\Models\BookCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Staff view of a seasonal collection: every field, its status and its books in order. */
class StaffCollectionResource extends JsonResource
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
            'starts_at' => $c->starts_at,
            'ends_at' => $c->ends_at,
            'is_active' => $c->is_active,
            'position' => $c->position,
            'status' => $c->status(),
            'books' => $c->books->map(fn ($b) => [
                'id' => $b->id,
                'title' => $b->title,
                // A book in the trash or not on sale is left out in the shop.
                'in_trash' => $b->trashed(),
            ])->values(),
            'created_at' => $c->created_at,
            'updated_at' => $c->updated_at,
        ];
    }
}

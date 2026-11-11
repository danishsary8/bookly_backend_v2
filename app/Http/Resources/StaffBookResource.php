<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffBookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'language' => $this->language,
            'age_min' => $this->age_min,
            'age_max' => $this->age_max,
            'school_grade' => $this->school_grade,
            'reader_rating' => $this->reader_rating !== null ? round((float) $this->reader_rating, 1) : null,
            'reader_rating_source' => $this->reader_rating_source,
            'level' => $this->level,
            'page_count' => $this->page_count,
            'publish_date' => $this->publish_date?->toDateString(),
            'publisher_id' => $this->publisher_id,
            'series_id' => $this->series_id,
            'series_order' => $this->series_order,
            'status' => $this->status ?? 'live',
            'publish_at' => $this->publish_at?->toIso8601String(),
            'authors' => $this->whenLoaded('authors', fn () => $this->authors->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values()),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'variants' => StaffBookVariantResource::collection($this->whenLoaded('variants')),
            'is_visible' => $this->whenLoaded('variants', fn () => $this->isPublic() && $this->variants->contains('is_active', true)),
            // Only on the list (GET /staff/books): copies ordered in the last 90 days.
            'sold_recent' => $this->when(isset($this->resource->sold_recent), fn () => (int) $this->resource->sold_recent),
            'deleted_at' => $this->deleted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

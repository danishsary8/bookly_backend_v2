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
            'page_count' => $this->page_count,
            'publish_date' => $this->publish_date?->toDateString(),
            'publisher_id' => $this->publisher_id,
            'series_id' => $this->series_id,
            'series_order' => $this->series_order,
            'authors' => $this->whenLoaded('authors', fn () => $this->authors->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values()),
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
            'variants' => StaffBookVariantResource::collection($this->whenLoaded('variants')),
            'is_visible' => $this->whenLoaded('variants', fn () => $this->deleted_at === null && $this->variants->contains('is_active', true)),
            'deleted_at' => $this->deleted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

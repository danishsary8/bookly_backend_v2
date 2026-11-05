<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SeriesResource extends JsonResource
{
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
            'books' => BookResource::collection($this->whenLoaded('books')),
        ];
    }
}

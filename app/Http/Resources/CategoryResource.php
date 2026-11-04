<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'parent_id' => $this->parent_id,
            'sort_order' => (int) $this->sort_order,
            'icon' => $this->icon,
            'books_count' => $this->whenCounted('books'),
            // In the tree: books in this category or any child (what the category page shows).
            'total_books' => $this->when(isset($this->total_books), fn () => (int) $this->total_books),
            'children' => self::collection($this->whenLoaded('children')),
        ];
    }
}

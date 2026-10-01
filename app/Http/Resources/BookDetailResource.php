<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class BookDetailResource extends BookResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'description' => $this->description,
            'page_count' => $this->page_count,
            'categories' => CategoryResource::collection($this->categories),
            'publisher' => $this->publisher ? ['id' => $this->publisher->id, 'name' => $this->publisher->name] : null,
            'series' => $this->series ? ['id' => $this->series->id, 'name' => $this->series->name, 'order' => $this->series_order] : null,
            'variants' => BookVariantResource::collection($this->activeVariants),
        ];
    }
}

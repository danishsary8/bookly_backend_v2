<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** A customer's own review: also shows the book and whether staff have hidden it. */
class OwnReviewResource extends ReviewResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'book' => $this->whenLoaded('book', fn () => ['id' => $this->book->id, 'title' => $this->book->title]),
            'is_visible' => $this->is_approved,
        ];
    }
}

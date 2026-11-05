<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReadingListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'slug' => $this->slug, 'title' => $this->title, 'description' => $this->description,
            'is_public' => $this->is_public, 'is_staff_pick' => $this->is_staff_pick,
            'owner_first_name' => preg_split('/\s+/u', trim($this->customer?->name ?? ''))[0],
            'books' => $this->books->map(fn ($book) => [
                'book' => new BookResource($book), 'position' => $book->pivot->position, 'note' => $book->pivot->note,
            ])->values(),
        ];
    }
}

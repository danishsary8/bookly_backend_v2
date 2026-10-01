<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'book' => ['id' => $this->book_id, 'title' => $this->book?->title],
            'customer' => ['id' => $this->customer_id, 'name' => $this->customer?->name, 'email' => $this->customer?->email],
            'order_item_id' => $this->order_item_id,
            'rating' => $this->rating,
            'comment' => $this->comment,
            'is_visible' => $this->is_approved,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

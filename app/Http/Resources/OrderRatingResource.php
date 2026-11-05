<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderRatingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'order_id' => $this->order_id, 'order_number' => $this->order?->order_number,
            'stars' => $this->stars, 'comment' => $this->is_comment_hidden ? null : $this->comment,
            'is_comment_hidden' => $this->is_comment_hidden,
            'created_at' => $this->created_at->toIso8601String(),
            'editable_until' => $this->created_at->copy()->addDays(7)->toIso8601String(),
            'can_edit' => now()->lt($this->created_at->copy()->addDays(7))];
    }
}

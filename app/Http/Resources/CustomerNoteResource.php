<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'body' => $this->body,
            'author' => [
                'id' => $this->author?->id,
                'name' => $this->author?->name ?? 'Staff',
            ],
            'created_at' => $this->created_at,
        ];
    }
}

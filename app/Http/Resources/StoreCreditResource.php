<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StoreCreditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'value_usd' => $this->value,
            'type' => $this->type->value ?? $this->type,
            'used_count' => $this->used_count,
            'max_uses' => $this->max_uses,
            'starts_at' => $this->starts_at,
            'expires_at' => $this->expires_at,
            'created_at' => $this->created_at,
        ];
    }
}

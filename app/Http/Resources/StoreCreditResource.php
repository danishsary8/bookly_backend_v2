<?php

namespace App\Http\Resources;

use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Coupon */
class StoreCreditResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'amount_usd' => $this->value,
            'expires_at' => $this->expires_at,
            'used' => $this->max_uses !== null && $this->used_count >= $this->max_uses,
            'expired' => $this->expires_at !== null && $this->expires_at->isPast(),
            'created_at' => $this->created_at,
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Staff view of an automatic offer (docs/superpowers/specs/2026-10-31-offers-design.md 6.7). */
class OfferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Offer $offer */
        $offer = $this->resource;

        return [
            'id' => $offer->id,
            'name' => $offer->name,
            'type' => $offer->type,
            'applies_to' => $offer->applies_to,
            'min_quantity' => $offer->min_quantity,
            'percent_off' => $offer->percent_off,
            'starts_at' => $offer->starts_at,
            'ends_at' => $offer->ends_at,
            'max_orders' => $offer->max_orders,
            'used_count' => $offer->used_count,
            'is_active' => $offer->is_active,
            'status' => $offer->status(),
            'targets' => $offer->targets->map(fn ($target) => [
                'type' => $target->type(),
                'id' => $target->targetId(),
                'label' => $target->label(),
                'in_trash' => $target->inTrash(),
            ])->values(),
            'orders_count' => (int) ($offer->orders_count ?? $offer->orders()->count()),
            'created_at' => $offer->created_at,
            'updated_at' => $offer->updated_at,
        ];
    }
}

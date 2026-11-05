<?php

namespace App\Http\Resources;

use App\Services\CurrencyService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Public view of a purchasable format. Staff see more fields via StaffBookVariantResource. */
class BookVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'format' => $this->format->value,
            'isbn' => $this->isbn,
            'price_usd' => $this->price_usd,
            'price_khr' => app(CurrencyService::class)->usdToKhr($this->price_usd),
            'in_stock' => $this->isAvailable(),
            'low_stock_remaining' => $this->lowStockBadge(),
            'cover_image_url' => $this->cover_image_url,
            'release_date' => $this->release_date?->format('Y-m-d'),
            'allow_backorder' => (bool) $this->allow_backorder,
            'is_preorder' => $this->isPreorder(),
            'is_backorder' => $this->isBackorder(),
        ];
    }
}

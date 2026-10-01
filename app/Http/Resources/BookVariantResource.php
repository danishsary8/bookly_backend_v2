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
            'cover_image_url' => $this->cover_image_url,
        ];
    }
}

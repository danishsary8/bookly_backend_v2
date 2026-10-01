<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** Staff view of a variant: public fields plus stock and admin fields. */
class StaffBookVariantResource extends BookVariantResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'book_id' => $this->book_id,
            'sku' => $this->sku,
            'stock_quantity' => $this->stock_quantity,
            'low_stock_threshold' => $this->low_stock_threshold,
            'is_low_stock' => $this->isLowStock(),
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

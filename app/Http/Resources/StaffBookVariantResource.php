<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** Staff view of a variant: public fields plus stock and admin fields. */
class StaffBookVariantResource extends BookVariantResource
{
    public function toArray(Request $request): array
    {
        $data = [
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

        // Supplier cost is admin-only, like restock costs in the stock history.
        if ($request->user()?->isAdmin()) {
            $data['cost_price_usd'] = $this->cost_price_usd !== null ? (string) $this->cost_price_usd : null;
        }

        return $data;
    }
}

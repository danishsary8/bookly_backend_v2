<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffStockResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $status = match (true) {
            $this->stock_quantity <= 0 => 'out',
            $this->stock_quantity <= $this->low_stock_threshold => 'low',
            default => 'in_stock',
        };

        return [
            'id' => $this->id,
            'book_id' => $this->book_id,
            'book_title' => $this->book?->title,
            'format' => $this->format instanceof \BackedEnum ? $this->format->value : (string) $this->format,
            'sku' => $this->sku,
            'isbn' => $this->isbn,
            'price_usd' => (string) $this->price_usd,
            'stock_quantity' => $this->stock_quantity,
            'low_stock_threshold' => $this->low_stock_threshold,
            'status' => $status,
            'is_active' => (bool) $this->is_active,
            'cover_image_url' => $this->cover_image_url,
        ];
    }
}

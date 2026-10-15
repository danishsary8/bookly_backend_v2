<?php

namespace App\Http\Resources;

use App\Models\StockAlert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StockAlert */
class StockAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $variant = $this->bookVariant;
        $book = $variant?->book;

        return [
            'id' => $this->id,
            'book_variant_id' => $this->book_variant_id,
            'created_at' => $this->created_at?->toISOString(),
            'notified_at' => $this->notified_at?->toISOString(),
            'book' => $book ? [
                'id' => $book->id,
                'title' => $book->title,
                'cover_image_url' => $book->cover_image_url,
            ] : null,
            'variant' => $variant ? [
                'id' => $variant->id,
                'format' => $variant->format instanceof \BackedEnum ? $variant->format->value : (string) $variant->format,
                'price_usd' => $variant->price_usd,
                'stock_quantity' => $variant->stock_quantity,
                'is_active' => $variant->is_active,
            ] : null,
        ];
    }
}

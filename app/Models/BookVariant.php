<?php

namespace App\Models;

use App\Enums\BookFormat;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'format', 'isbn', 'sku', 'price_usd', 'stock_quantity', 'low_stock_threshold', 'cover_image_url',
    ];

    protected function casts(): array
    {
        return [
            'format' => BookFormat::class,
            'price_usd' => 'decimal:2',
            'stock_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function isLowStock(): bool
    {
        return $this->stock_quantity <= $this->low_stock_threshold;
    }
}

<?php

namespace App\Models;

use App\Enums\BookFormat;
use App\Services\Marketing\PriceDropAlerts;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class BookVariant extends Model
{
    use HasFactory;

    protected $fillable = [
        'book_id', 'format', 'isbn', 'sku', 'price_usd', 'stock_quantity', 'low_stock_threshold', 'cover_image_url', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'format' => BookFormat::class,
            'price_usd' => 'decimal:2',
            'stock_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Any saved price cut (single edit, bulk edit, CSV import) may queue wishlist price-drop emails, once the change
     * is committed. A mail problem never blocks the save: the outbox only gets rows here.
     */
    protected static function booted(): void
    {
        static::updated(function (BookVariant $variant) {
            if ($variant->wasChanged('price_usd')) {
                $old = $variant->getOriginal('price_usd');
                DB::afterCommit(fn () => app(PriceDropAlerts::class)->priceChanged($variant, $old));
            }
        });
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** Digital formats never run out; physical ones need stock. */
    public function isAvailable(): bool
    {
        return $this->is_active && ($this->format->isDigital() || $this->stock_quantity > 0);
    }

    public function isLowStock(): bool
    {
        return $this->stock_quantity <= $this->low_stock_threshold;
    }
}

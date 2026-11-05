<?php

namespace App\Models;

use App\Enums\BookFormat;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'book_variant_id', 'quantity', 'unit_price', 'subtotal', 'discount_amount',
        'title', 'format', 'sku', 'isbn', 'authors',
        'is_preorder', 'is_backorder', 'expected_date',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'unit_price' => 'decimal:2', 'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2',
            'format' => BookFormat::class, 'authors' => 'array',
            'is_preorder' => 'boolean', 'is_backorder' => 'boolean', 'expected_date' => 'date',
        ];
    }

    /** The book details frozen on the line at checkout, for creating it. */
    public static function detailsOf(BookVariant $variant): array
    {
        return [
            'title' => $variant->book?->title,
            'format' => $variant->format,
            'sku' => $variant->sku,
            'isbn' => $variant->isbn,
            'authors' => $variant->book?->authors->sortBy('id')->pluck('name')->values()->all() ?? [],
            'is_preorder' => $variant->isPreorder(),
            'is_backorder' => $variant->isBackorder(),
            'expected_date' => $variant->isPreorder() ? $variant->release_date : null,
        ];
    }

    /*
     * What the line was bought as. Every line has these since the 2026_10_13 migration; the live book is only a
     * fallback for a line written without them.
     */
    public function bookTitle(): ?string
    {
        return $this->title ?? $this->variant?->book?->title;
    }

    public function bookFormat(): ?BookFormat
    {
        return $this->format ?? $this->variant?->format;
    }

    public function bookSku(): ?string
    {
        return $this->sku ?? $this->variant?->sku;
    }

    public function bookIsbn(): ?string
    {
        return $this->title !== null ? $this->isbn : $this->variant?->isbn;
    }

    /** @return list<string> */
    public function bookAuthors(): array
    {
        return $this->authors ?? $this->variant?->book?->authors->sortBy('id')->pluck('name')->values()->all() ?? [];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(BookVariant::class, 'book_variant_id');
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }
}

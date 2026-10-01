<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = ['book_variant_id', 'quantity', 'unit_price', 'subtotal'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'decimal:2', 'subtotal' => 'decimal:2'];
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

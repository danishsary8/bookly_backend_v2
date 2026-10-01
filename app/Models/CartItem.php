<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = ['book_variant_id', 'quantity', 'unit_price_at_add'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price_at_add' => 'decimal:2'];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(BookVariant::class, 'book_variant_id');
    }
}

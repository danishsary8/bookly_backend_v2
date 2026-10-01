<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = ['book_id', 'customer_id', 'order_item_id', 'rating', 'comment', 'is_approved'];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'is_approved' => 'boolean'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}

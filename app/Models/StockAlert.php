<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAlert extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'customer_id',
        'book_variant_id',
        'created_at',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function bookVariant(): BelongsTo
    {
        return $this->belongsTo(BookVariant::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderRating extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['stars', 'comment'];

    protected function casts(): array
    {
        return ['stars' => 'integer', 'is_comment_hidden' => 'boolean', 'created_at' => 'datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

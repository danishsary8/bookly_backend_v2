<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Wishlist extends Model
{
    public const STATUS_WANT_TO_READ = 'want_to_read';

    public const STATUS_READING = 'reading';

    public const STATUS_FINISHED = 'finished';

    public const STATUSES = [
        self::STATUS_WANT_TO_READ,
        self::STATUS_READING,
        self::STATUS_FINISHED,
    ];

    protected $fillable = ['customer_id', 'book_id', 'status'];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One thing a "selected" offer covers: exactly one of book, category or series (a CHECK in the database). */
class OfferTarget extends Model
{
    protected $fillable = ['offer_id', 'book_id', 'category_id', 'series_id'];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class)->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class)->withTrashed();
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class)->withTrashed();
    }
}

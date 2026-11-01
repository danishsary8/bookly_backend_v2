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

    /** 'book', 'category' or 'series'. */
    public function type(): string
    {
        return $this->book_id !== null ? 'book' : ($this->category_id !== null ? 'category' : 'series');
    }

    public function targetId(): int
    {
        return (int) ($this->book_id ?? $this->category_id ?? $this->series_id);
    }

    /** The book title, category name or series name (null if the row is gone). */
    public function label(): ?string
    {
        return match ($this->type()) {
            'book' => $this->book?->title,
            'category' => $this->category?->name,
            default => $this->series?->name,
        };
    }

    /** In the trash (or gone): the target matches no book until it is restored. */
    public function inTrash(): bool
    {
        $model = match ($this->type()) {
            'book' => $this->book,
            'category' => $this->category,
            default => $this->series,
        };

        return $model === null || $model->trashed();
    }
}

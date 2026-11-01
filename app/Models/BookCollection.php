<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A seasonal collection of hand-picked books ("Rainy season reads"), owner 2026-11-01. Named BookCollection because
 * "Collection" is Laravel's list class; the table is `collections`.
 */
class BookCollection extends Model
{
    use HasFactory;

    protected $table = 'collections';

    protected $fillable = ['slug', 'title_en', 'title_km', 'description_en', 'description_km', 'starts_at', 'ends_at', 'is_active', 'position'];

    protected $attributes = ['is_active' => false, 'position' => 0];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'collection_books', 'collection_id', 'book_id')
            ->withPivot('position')
            ->orderBy('collection_books.position');
    }

    /** Switched on, started and not ended. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    /** live, scheduled (starts later), ended or hidden: for the admin list. */
    public function status(): string
    {
        return match (true) {
            ! $this->is_active => 'hidden',
            $this->ends_at !== null && $this->ends_at->lte(now()) => 'ended',
            $this->starts_at !== null && $this->starts_at->gt(now()) => 'scheduled',
            default => 'live',
        };
    }
}

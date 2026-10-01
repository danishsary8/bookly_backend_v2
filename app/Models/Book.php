<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Book extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title', 'description', 'publisher_id', 'series_id', 'series_order',
        'language', 'page_count', 'publish_date',
    ];

    protected $hidden = ['search_vector'];

    protected function casts(): array
    {
        return ['publish_date' => 'date'];
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'book_authors');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'book_categories');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(BookVariant::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function activeVariants(): HasMany
    {
        return $this->hasMany(BookVariant::class)->where('is_active', true);
    }

    public function approvedReviews(): HasMany
    {
        return $this->hasMany(Review::class)->where('is_approved', true);
    }

    /** Books customers can see: at least one active format. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereHas('variants', fn (Builder $q) => $q->where('is_active', true));
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->whereRaw("search_vector @@ plainto_tsquery('english', ?)", [$term]);
    }

    public function scopeOrderByRelevance(Builder $query, string $term): Builder
    {
        return $query->orderByRaw("ts_rank(search_vector, plainto_tsquery('english', ?)) DESC", [$term]);
    }
}

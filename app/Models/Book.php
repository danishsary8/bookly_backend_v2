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

    /**
     * Turns what the customer typed into a prefix tsquery: "sherlock hol" -> "sherlock:* & hol:*", so results
     * appear while typing. Only letters, digits and combining marks (Khmer) are kept, so the query is always valid.
     */
    public static function searchQuery(string $term): ?string
    {
        $words = preg_split('/[^\p{L}\p{N}\p{M}]+/u', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY);

        return $words === [] ? null : implode(' & ', array_map(fn (string $word) => $word.':*', array_slice($words, 0, 8)));
    }

    /** Matches the title and description, or an author, series or category name. */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $tsquery = self::searchQuery($term);
        if ($tsquery === null) {
            return $query->whereRaw('false');
        }

        return $query->where(fn (Builder $q) => $q
            ->whereRaw("books.search_vector @@ to_tsquery('english', ?)", [$tsquery])
            ->orWhereHas('authors', fn (Builder $a) => $a->whereRaw("to_tsvector('simple', authors.name) @@ to_tsquery('simple', ?)", [$tsquery]))
            ->orWhereHas('series', fn (Builder $s) => $s->whereRaw("to_tsvector('english', series.name) @@ to_tsquery('english', ?)", [$tsquery]))
            ->orWhereHas('categories', fn (Builder $c) => $c->whereRaw("to_tsvector('english', categories.name) @@ to_tsquery('english', ?)", [$tsquery])));
    }

    /** Title matches first, then author, series and category name matches; text rank breaks ties. */
    public function scopeOrderByRelevance(Builder $query, string $term): Builder
    {
        $tsquery = self::searchQuery($term) ?? '';

        return $query->orderByRaw(
            "ts_rank(books.search_vector, to_tsquery('english', ?))
            + CASE WHEN to_tsvector('english', books.title) @@ to_tsquery('english', ?) THEN 1 ELSE 0 END
            + CASE WHEN EXISTS (SELECT 1 FROM book_authors ba JOIN authors a ON a.id = ba.author_id
                WHERE ba.book_id = books.id AND to_tsvector('simple', a.name) @@ to_tsquery('simple', ?)) THEN 0.6 ELSE 0 END
            + CASE WHEN EXISTS (SELECT 1 FROM series s
                WHERE s.id = books.series_id AND to_tsvector('english', s.name) @@ to_tsquery('english', ?)) THEN 0.4 ELSE 0 END
            + CASE WHEN EXISTS (SELECT 1 FROM book_categories bc JOIN categories c ON c.id = bc.category_id
                WHERE bc.book_id = books.id AND to_tsvector('english', c.name) @@ to_tsquery('english', ?)) THEN 0.2 ELSE 0 END DESC",
            [$tsquery, $tsquery, $tsquery, $tsquery, $tsquery],
        );
    }
}

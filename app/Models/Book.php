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
     * Splits what the customer typed into lower-case words, each used as a prefix ("sherlock hol" -> sherlock:*, hol:*),
     * so results appear while typing. Only letters, digits and combining marks (Khmer) are kept, so a word can never be
     * a tsquery operator; at most 8 words.
     *
     * @return list<string>
     */
    public static function searchPrefixes(string $term): array
    {
        $words = preg_split('/[^\p{L}\p{N}\p{M}]+/u', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY);

        return array_map(fn (string $word) => $word.':*', array_slice($words, 0, 8));
    }

    /**
     * Every word must match somewhere: the title or description, an author, the series or a category name.
     * So "austen emma" finds Emma by Jane Austen, and "sherlock" finds the books of the Sherlock Holmes series.
     * The title is also matched unstemmed ('simple'), because English stemming breaks half-typed words
     * ("runni" is not a prefix of the stem "run"). A stop word ("the", "of") never blocks a longer search.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $prefixes = self::searchPrefixes($term);
        $code = self::searchCode($term);
        if ($prefixes === [] && $code === null) {
            return $query->whereRaw('false');
        }

        return $query->where(function (Builder $match) use ($prefixes, $code) {
            if ($prefixes !== []) {
                $match->where(function (Builder $text) use ($prefixes) {
                    foreach ($prefixes as $prefix) {
                        $text->where(fn (Builder $q) => $q
                            ->whereRaw("books.search_vector @@ to_tsquery('english', ?)", [$prefix])
                            ->orWhereRaw("to_tsvector('simple', books.title) @@ to_tsquery('simple', ?)", [$prefix])
                            ->orWhereHas('authors', fn (Builder $a) => $a->whereRaw("to_tsvector('simple', authors.name) @@ to_tsquery('simple', ?)", [$prefix]))
                            ->orWhereHas('series', fn (Builder $s) => $s->whereRaw("to_tsvector('english', series.name) @@ to_tsquery('english', ?)", [$prefix]))
                            ->orWhereHas('categories', fn (Builder $c) => $c->whereRaw("to_tsvector('english', categories.name) @@ to_tsquery('english', ?)", [$prefix]))
                            ->when(count($prefixes) > 1, fn (Builder $q) => $q->orWhereRaw("numnode(to_tsquery('english', ?)) = 0", [$prefix])));
                    }
                });
            }

            // A typed SKU or ISBN finds its book directly (an active format only).
            if ($code !== null) {
                $match->orWhereHas('variants', fn (Builder $v) => $v->where('is_active', true)->where(fn (Builder $w) => $w
                    ->whereRaw('upper(sku) = ?', [$code['sku']])
                    ->when($code['isbn'] !== null, fn (Builder $w) => $w->orWhereRaw("replace(replace(isbn, '-', ''), ' ', '') = ?", [$code['isbn']]))));
            }
        });
    }

    /**
     * One word of 3-50 letters, digits, - or _ is also tried as an exact SKU (any case), and as an ISBN when it is
     * 10 or 13 digits once hyphens and spaces are removed ("978-0-14-143958-7"). Null for normal words.
     *
     * @return array{sku: string, isbn: ?string}|null
     */
    public static function searchCode(string $term): ?array
    {
        $term = trim($term);
        $digits = strtoupper(str_replace(['-', ' '], '', $term));
        $isbn = preg_match('/^(\d{9}[\dX]|\d{13})$/', $digits) ? $digits : null;
        if ($isbn === null && ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{2,49}$/', $term)) {
            return null;
        }

        return ['sku' => strtoupper($term), 'isbn' => $isbn];
    }

    /** Title matches first, then author, series and category name matches; text rank breaks ties. */
    public function scopeOrderByRelevance(Builder $query, string $term): Builder
    {
        $any = implode(' | ', self::searchPrefixes($term));

        return $query->orderByRaw(
            "ts_rank(books.search_vector, to_tsquery('english', ?))
            + CASE WHEN to_tsvector('simple', books.title) @@ to_tsquery('simple', ?) THEN 1 ELSE 0 END
            + CASE WHEN EXISTS (SELECT 1 FROM book_authors ba JOIN authors a ON a.id = ba.author_id
                WHERE ba.book_id = books.id AND to_tsvector('simple', a.name) @@ to_tsquery('simple', ?)) THEN 0.6 ELSE 0 END
            + CASE WHEN EXISTS (SELECT 1 FROM series s
                WHERE s.id = books.series_id AND to_tsvector('english', s.name) @@ to_tsquery('english', ?)) THEN 0.4 ELSE 0 END
            + CASE WHEN EXISTS (SELECT 1 FROM book_categories bc JOIN categories c ON c.id = bc.category_id
                WHERE bc.book_id = books.id AND to_tsvector('english', c.name) @@ to_tsquery('english', ?)) THEN 0.2 ELSE 0 END DESC",
            [$any, $any, $any, $any, $any],
        );
    }
}

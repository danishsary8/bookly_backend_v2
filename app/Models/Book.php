<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Book extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'title', 'description', 'status', 'publish_at', 'publisher_id', 'series_id', 'series_order',
        'language', 'page_count', 'publish_date',
    ];

    protected $hidden = ['search_vector'];

    /**
     * The column has no time zone, so a time sent with an offset ("09:00+07:00" from the admin form) is turned into UTC
     * before it is stored; otherwise it would be saved as 09:00 UTC, 7 hours late.
     */
    protected function publishAt(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === null || $value === '' ? null : Carbon::parse($value)->utc()->format('Y-m-d H:i:s'),
        );
    }

    protected function casts(): array
    {
        return [
            'publish_date' => 'date',
            'publish_at' => 'datetime',
        ];
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

    public function isPublic(): bool
    {
        if ($this->deleted_at !== null) {
            return false;
        }

        if ($this->status === 'live') {
            return true;
        }

        if ($this->status === 'scheduled' && $this->publish_at !== null && $this->publish_at->isPast()) {
            return true;
        }

        return false;
    }

    /** Books customers can see: live or reached scheduled publish time, and at least one active format. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', 'live')
                ->orWhere(function (Builder $sub) {
                    $sub->where('status', 'scheduled')
                        ->where('publish_at', '<=', now());
                });
        })->whereHas('variants', fn (Builder $q) => $q->where('is_active', true));
    }

    /**
     * Splits the query into at most 8 lower-case words, exactly as before for Latin text. A zero-width space (U+200B,
     * which Khmer keyboards put between words) is a separator too, so a Khmer phrase becomes several Khmer words that
     * must all match.
     *
     * @return list<string>
     */
    public static function searchWords(string $term): array
    {
        $words = preg_split('/[^\p{L}\p{N}\p{M}]+/u', mb_strtolower($term), -1, PREG_SPLIT_NO_EMPTY);

        return array_slice($words === false ? [] : $words, 0, 8);
    }

    /**
     * Checks if a word contains Khmer letters (Unicode U+1780-U+17FF or U+19E0-U+19FF).
     */
    public static function isKhmerWord(string $word): bool
    {
        return (bool) preg_match('/[\x{1780}-\x{17FF}\x{19E0}-\x{19FF}]/u', $word);
    }

    /**
     * Splits what the customer typed into lower-case words, each used as a prefix ("sherlock hol" -> sherlock:*, hol:*),
     * so results appear while typing. Only letters, digits and combining marks are kept, at most 8 words.
     *
     * @return list<string>
     */
    public static function searchPrefixes(string $term): array
    {
        $words = self::searchWords($term);

        return array_map(fn (string $word) => $word.':*', $words);
    }

    /**
     * Every word must match somewhere: the title or description, an author, the series or a category name.
     * Khmer words are matched via case-insensitive substring ILIKE using trigram indexes, with U+200B removed.
     * Latin words use full-text search vectors with prefix matching.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $words = self::searchWords($term);
        $code = self::searchCode($term);
        if ($words === [] && $code === null) {
            return $query->whereRaw('false');
        }

        return $query->where(function (Builder $match) use ($words, $code) {
            if ($words !== []) {
                $match->where(function (Builder $text) use ($words) {
                    foreach ($words as $word) {
                        if (self::isKhmerWord($word)) {
                            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word);
                            $like = "%{$escaped}%";

                            $text->where(fn (Builder $q) => $q
                                ->where('books.title', 'ILIKE', $like)
                                ->orWhereRaw("replace(books.title, chr(8203), '') ILIKE ?", [$like])
                                ->orWhere('books.description', 'ILIKE', $like)
                                ->orWhereRaw("replace(coalesce(books.description, ''), chr(8203), '') ILIKE ?", [$like])
                                ->orWhereHas('authors', fn (Builder $a) => $a
                                    ->where('authors.name', 'ILIKE', $like)
                                    ->orWhereRaw("replace(authors.name, chr(8203), '') ILIKE ?", [$like]))
                                ->orWhereHas('series', fn (Builder $s) => $s
                                    ->where('series.name', 'ILIKE', $like)
                                    ->orWhereRaw("replace(series.name, chr(8203), '') ILIKE ?", [$like]))
                                ->orWhereHas('categories', fn (Builder $c) => $c
                                    ->where('categories.name', 'ILIKE', $like)
                                    ->orWhereRaw("replace(categories.name, chr(8203), '') ILIKE ?", [$like])));
                        } else {
                            $prefix = $word.':*';
                            $text->where(fn (Builder $q) => $q
                                ->whereRaw("books.search_vector @@ to_tsquery('english', ?)", [$prefix])
                                ->orWhereRaw("to_tsvector('simple', books.title) @@ to_tsquery('simple', ?)", [$prefix])
                                ->orWhereRaw("to_tsvector('simple', coalesce(books.description, '')) @@ to_tsquery('simple', ?)", [$prefix])
                                ->orWhereHas('authors', fn (Builder $a) => $a->whereRaw("to_tsvector('simple', authors.name) @@ to_tsquery('simple', ?)", [$prefix]))
                                ->orWhereHas('series', fn (Builder $s) => $s
                                    ->whereRaw("to_tsvector('english', series.name) @@ to_tsquery('english', ?)", [$prefix])
                                    ->orWhereRaw("to_tsvector('simple', series.name) @@ to_tsquery('simple', ?)", [$prefix]))
                                ->orWhereHas('categories', fn (Builder $c) => $c
                                    ->whereRaw("to_tsvector('english', categories.name) @@ to_tsquery('english', ?)", [$prefix])
                                    ->orWhereRaw("to_tsvector('simple', categories.name) @@ to_tsquery('simple', ?)", [$prefix]))
                                ->when(count($words) > 1, fn (Builder $q) => $q->orWhereRaw("numnode(to_tsquery('english', ?)) = 0", [$prefix])));
                        }
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

    /** Minimum pg_trgm word similarity per word for the typo fallback ("frankenstien" ~ 0.69, unrelated words ~ 0). */
    public const TYPO_SIMILARITY = 0.4;

    /**
     * The words worth a typo fallback: Latin words of 3+ letters (Khmer has its own search; "of", "a" are noise).
     *
     * @return list<string>
     */
    public static function typoSearchWords(string $term): array
    {
        // A code-like search (has a digit, - or _: an ISBN or SKU such as "DRAC-OLD") is matched exactly, never guessed.
        if (preg_match('/[\d_-]/', $term)) {
            return [];
        }

        return array_values(array_filter(
            self::searchWords($term),
            fn (string $word) => ! self::isKhmerWord($word) && mb_strlen($word) >= 3,
        ));
    }

    /**
     * Typo-tolerant match for when the normal search finds nothing. Like the normal search, every word must match:
     * each one needs a pg_trgm word similarity of at least TYPO_SIMILARITY with the title, an author or the series.
     *
     * @param  list<string>  $words
     */
    public function scopeTypoSearch(Builder $query, array $words): Builder
    {
        foreach ($words as $word) {
            $query->whereRaw(self::typoScoreSql().' >= ?', [$word, $word, $word, self::TYPO_SIMILARITY]);
        }

        return $query;
    }

    /** @param  list<string>  $words */
    public function scopeOrderByTypoSimilarity(Builder $query, array $words): Builder
    {
        $sum = implode(' + ', array_fill(0, count($words), self::typoScoreSql()));
        $bindings = array_merge(...array_map(fn ($word) => [$word, $word, $word], $words));

        return $query->orderByRaw("({$sum}) DESC", $bindings);
    }

    /** Best pg_trgm word similarity of one word with the title, an author or the series (indexes: Khmer search migration). */
    private static function typoScoreSql(): string
    {
        return 'GREATEST('
            .'word_similarity(?, books.title), '
            .'coalesce((select max(word_similarity(?, authors.name)) from authors join book_authors on book_authors.author_id = authors.id where book_authors.book_id = books.id), 0), '
            .'coalesce((select word_similarity(?, series.name) from series where series.id = books.series_id), 0))';
    }

    /** Title matches first, then author, series and category name matches; text rank breaks ties. */
    public function scopeOrderByRelevance(Builder $query, string $term): Builder
    {
        $words = self::searchWords($term);
        $latinWords = array_values(array_filter($words, fn (string $w) => ! self::isKhmerWord($w)));
        $khmerWords = array_values(array_filter($words, fn (string $w) => self::isKhmerWord($w)));

        $sqlParts = [];
        $bindings = [];

        if ($latinWords !== []) {
            $latinPrefixes = array_map(fn (string $w) => $w.':*', $latinWords);
            $any = implode(' | ', $latinPrefixes);

            $sqlParts[] = "ts_rank(books.search_vector, to_tsquery('english', ?))";
            $bindings[] = $any;

            $sqlParts[] = "CASE WHEN to_tsvector('simple', books.title) @@ to_tsquery('simple', ?) THEN 1 ELSE 0 END";
            $bindings[] = $any;

            $sqlParts[] = "CASE WHEN EXISTS (SELECT 1 FROM book_authors ba JOIN authors a ON a.id = ba.author_id
                WHERE ba.book_id = books.id AND to_tsvector('simple', a.name) @@ to_tsquery('simple', ?)) THEN 0.6 ELSE 0 END";
            $bindings[] = $any;

            $sqlParts[] = "CASE WHEN EXISTS (SELECT 1 FROM series s
                WHERE s.id = books.series_id AND to_tsvector('english', s.name) @@ to_tsquery('english', ?)) THEN 0.4 ELSE 0 END";
            $bindings[] = $any;

            $sqlParts[] = "CASE WHEN EXISTS (SELECT 1 FROM book_categories bc JOIN categories c ON c.id = bc.category_id
                WHERE bc.book_id = books.id AND to_tsvector('english', c.name) @@ to_tsquery('english', ?)) THEN 0.2 ELSE 0 END";
            $bindings[] = $any;
        }

        if ($khmerWords !== []) {
            foreach ($khmerWords as $kw) {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $kw);
                $like = "%{$escaped}%";

                $sqlParts[] = "CASE WHEN (books.title ILIKE ? OR replace(books.title, chr(8203), '') ILIKE ?) THEN 1.0 ELSE 0.0 END";
                $bindings[] = $like;
                $bindings[] = $like;

                $sqlParts[] = "CASE WHEN EXISTS (SELECT 1 FROM book_authors ba JOIN authors a ON a.id = ba.author_id
                    WHERE ba.book_id = books.id AND (a.name ILIKE ? OR replace(a.name, chr(8203), '') ILIKE ?)) THEN 0.6 ELSE 0.0 END";
                $bindings[] = $like;
                $bindings[] = $like;

                $sqlParts[] = "CASE WHEN EXISTS (SELECT 1 FROM series s
                    WHERE s.id = books.series_id AND (s.name ILIKE ? OR replace(s.name, chr(8203), '') ILIKE ?)) THEN 0.4 ELSE 0.0 END";
                $bindings[] = $like;
                $bindings[] = $like;

                $sqlParts[] = "CASE WHEN EXISTS (SELECT 1 FROM book_categories bc JOIN categories c ON c.id = bc.category_id
                    WHERE bc.book_id = books.id AND (c.name ILIKE ? OR replace(c.name, chr(8203), '') ILIKE ?)) THEN 0.2 ELSE 0.0 END";
                $bindings[] = $like;
                $bindings[] = $like;
            }
        }

        if ($sqlParts === []) {
            return $query->latest();
        }

        $orderSql = implode(' + ', $sqlParts).' DESC';

        return $query->orderByRaw($orderSql, $bindings);
    }
}

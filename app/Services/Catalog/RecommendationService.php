<?php

namespace App\Services\Catalog;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Models\Book;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RecommendationService
{
    /** How many ids a book's cached list keeps (the most any caller can ask for). */
    public const CACHED_IDS = 12;

    private const VERSION_KEY = 'catalog:recommendations-version';

    /**
     * Retrieve recommended books for a specific book, filled up to $limit in this order: same series (next books
     * first), bought together, same category + author, same categories (best sellers first), same authors, store
     * best sellers. Never the book itself, no duplicates, in-stock and visible only.
     *
     * The full list (CACHED_IDS ids) is cached for an hour under the catalogue version, so any staff change to any
     * book starts fresh lists for every book. Stock and visibility are checked again on every read, so a book that
     * sold out or was hidden after the list was cached never shows; the result is then cut to $limit.
     *
     * @return list<int> Book IDs in order of relevance.
     */
    public function getRecommendedIdsForBook(Book $targetBook, int $limit = 6): array
    {
        $limit = max(1, min(self::CACHED_IDS, $limit));
        $key = 'books:'.$targetBook->id.':recommendations:v'.self::catalogVersion();

        $ids = Cache::remember($key, 3600, fn () => $this->computeIdsForBook($targetBook));

        return array_slice($this->filterInStockAndVisibleIds($ids), 0, $limit);
    }

    /** The catalogue version used in the cache keys of the book lists. */
    public static function catalogVersion(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    /**
     * Staff changed a book (created, edited, deleted, restored, authors, categories or series): every cached book
     * list is now stale. Old entries are not deleted; nobody reads them again and they expire within the hour.
     */
    public static function catalogChanged(): void
    {
        Cache::forever(self::VERSION_KEY, self::catalogVersion() + 1);
    }

    /** @return list<int> */
    private function computeIdsForBook(Book $targetBook): array
    {
        $limit = self::CACHED_IDS;

        // 0. Same series: the next books first, then the earlier ones
        $collected = collect($targetBook->series_id
            ? $this->querySeriesBookIds($targetBook->series_id, $targetBook->series_order, [$targetBook->id], $limit)
            : []);

        // 1. Co-purchases in completed orders (>= 2 shared orders)
        $coBookIds = $this->queryCoPurchasedBookIds($targetBook->id);
        if (! empty($coBookIds)) {
            $validCoBooks = $this->filterInStockAndVisibleIds($coBookIds);
            $collected = $collected->concat($validCoBooks)->unique()->values();
        }

        if ($collected->count() >= $limit) {
            return $collected->take($limit)->all();
        }

        $excludeIds = array_merge([$targetBook->id], $collected->all());
        $needed = $limit - $collected->count();

        // 2. Same primary category AND same author (highest topical match)
        $categoryIds = $targetBook->categories()->pluck('categories.id')->all();
        $authorIds = $targetBook->authors()->pluck('authors.id')->all();

        if (! empty($categoryIds) && ! empty($authorIds)) {
            $sameCategoryAndAuthor = $this->baseBuyableQuery()
                ->whereNotIn('books.id', $excludeIds)
                ->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $categoryIds))
                ->whereHas('authors', fn ($a) => $a->whereIn('authors.id', $authorIds))
                ->leftJoinSub($this->unitsSoldSubquery(), 'ca_sales', 'ca_sales.book_id', '=', 'books.id')
                ->orderByRaw('ca_sales.units_sold DESC NULLS LAST')
                ->orderByDesc('books.id')
                ->limit($needed)
                ->pluck('books.id')
                ->all();

            $collected = $collected->concat($sameCategoryAndAuthor)->unique()->values();
            $excludeIds = array_merge([$targetBook->id], $collected->all());
            $needed = $limit - $collected->count();
        }

        // 3. Same categories (ordered by sales volume / rating)
        if ($needed > 0 && ! empty($categoryIds)) {
            $sameCategory = $this->queryCategoryBestSellers($categoryIds, $excludeIds, $needed);
            $collected = $collected->concat($sameCategory)->unique()->values();
            $excludeIds = array_merge([$targetBook->id], $collected->all());
            $needed = $limit - $collected->count();
        }

        // 4. Same author(s) (ordered by rating / recency)
        if ($needed > 0 && ! empty($authorIds)) {
            $sameAuthor = $this->baseBuyableQuery()
                ->whereNotIn('books.id', $excludeIds)
                ->whereHas('authors', fn ($a) => $a->whereIn('authors.id', $authorIds))
                ->withAvg('approvedReviews as rating_avg', 'rating')
                ->orderByRaw('rating_avg DESC NULLS LAST')
                ->orderByDesc('books.id')
                ->limit($needed)
                ->pluck('books.id')
                ->all();

            $collected = $collected->concat($sameAuthor)->unique()->values();
            $excludeIds = array_merge([$targetBook->id], $collected->all());
            $needed = $limit - $collected->count();
        }

        // 5. Still short (few books, brand-new book): store best sellers fill the row up to the limit.
        //    Same-category best sellers already came in step 3, which is sorted by units sold.
        if ($needed > 0) {
            $collected = $collected->concat($this->queryPopularBookIds($excludeIds, $needed))->unique()->values();
        }

        return $collected->take($limit)->all();
    }

    /**
     * Retrieve recommendations based on the books in cart or recently viewed.
     *
     * @param  list<int>  $cartBookIds
     * @param  list<int>  $excludeIds
     * @return list<int>
     */
    public function getRecommendedIdsForCart(array $cartBookIds, array $excludeIds = [], int $limit = 6): array
    {
        $limit = max(1, min(12, $limit));
        $allExclude = array_values(array_unique(array_merge($cartBookIds, $excludeIds)));

        if (empty($cartBookIds)) {
            return $this->queryPopularBookIds($allExclude, $limit);
        }

        // 0. Next books of every series in the cart (then the earlier ones), taking turns between series
        $collected = collect($this->queryCartSeriesBookIds($cartBookIds, $allExclude, $limit));
        $allExclude = array_values(array_unique(array_merge($allExclude, $collected->all())));

        if ($collected->count() >= $limit) {
            return $collected->take($limit)->all();
        }

        // 1. Extract categories and authors of items in the cart
        $categoryIds = DB::table('book_categories')
            ->whereIn('book_id', $cartBookIds)
            ->pluck('category_id')
            ->unique()
            ->all();

        $authorIds = DB::table('book_authors')
            ->whereIn('book_id', $cartBookIds)
            ->pluck('author_id')
            ->unique()
            ->all();

        // 2. Complementary books from the same categories ordered by units sold
        if (! empty($categoryIds)) {
            $catMatches = $this->queryCategoryBestSellers($categoryIds, $allExclude, $limit - $collected->count());
            $collected = $collected->concat($catMatches)->unique()->values();
        }

        if ($collected->count() < $limit && ! empty($authorIds)) {
            $needed = $limit - $collected->count();
            $authorExclude = array_merge($allExclude, $collected->all());
            $authorMatches = $this->baseBuyableQuery()
                ->whereNotIn('books.id', $authorExclude)
                ->whereHas('authors', fn ($a) => $a->whereIn('authors.id', $authorIds))
                ->orderByDesc('books.id')
                ->limit($needed)
                ->pluck('books.id')
                ->all();

            $collected = $collected->concat($authorMatches)->unique()->values();
        }

        // 3. Fallback to store best sellers
        if ($collected->count() < $limit) {
            $needed = $limit - $collected->count();
            $popularExclude = array_merge($allExclude, $collected->all());
            $popular = $this->queryPopularBookIds($popularExclude, $needed);
            $collected = $collected->concat($popular)->unique()->values();
        }

        return $collected->take($limit)->all();
    }

    /**
     * Query top-rated and best-selling books across the entire catalog.
     *
     * @param  list<int>  $excludeIds
     * @return list<int>
     */
    public function queryPopularBookIds(array $excludeIds = [], int $limit = 6): array
    {
        return $this->baseBuyableQuery()
            ->when(! empty($excludeIds), fn ($q) => $q->whereNotIn('books.id', $excludeIds))
            ->leftJoinSub($this->unitsSoldSubquery(), 'sales', 'sales.book_id', '=', 'books.id')
            ->withAvg('approvedReviews as rating_avg', 'rating')
            ->orderByRaw('sales.units_sold DESC NULLS LAST')
            ->orderByRaw('rating_avg DESC NULLS LAST')
            ->orderByDesc('books.id')
            ->limit($limit)
            ->pluck('books.id')
            ->all();
    }

    /**
     * Buyable books of one series, never the excluded ones: books after $fromOrder first (reading order), then the
     * earlier ones (from book 1), then books without a number. With no $fromOrder, plain reading order.
     *
     * @param  list<int>  $excludeIds
     * @return list<int>
     */
    private function querySeriesBookIds(int $seriesId, ?int $fromOrder, array $excludeIds, int $limit): array
    {
        return $this->baseBuyableQuery()
            ->where('books.series_id', $seriesId)
            ->when(! empty($excludeIds), fn ($q) => $q->whereNotIn('books.id', $excludeIds))
            ->when($fromOrder !== null, fn ($q) => $q->orderByRaw(
                'CASE WHEN books.series_order > ? THEN 0 WHEN books.series_order IS NOT NULL THEN 1 ELSE 2 END',
                [$fromOrder],
            ))
            ->orderByRaw('books.series_order ASC NULLS LAST')
            ->orderBy('books.id')
            ->limit($limit)
            ->pluck('books.id')
            ->all();
    }

    /**
     * Series suggestions for a cart: for each series in the cart, the books after the furthest one in the cart
     * (then the earlier ones). Series take turns, so the next book of every series comes before the second of any.
     *
     * @param  list<int>  $cartBookIds
     * @param  list<int>  $excludeIds  already includes the cart books
     * @return list<int>
     */
    private function queryCartSeriesBookIds(array $cartBookIds, array $excludeIds, int $limit): array
    {
        $furthest = Book::query()
            ->whereIn('id', $cartBookIds)
            ->whereNotNull('series_id')
            ->groupBy('series_id')
            ->selectRaw('series_id, MAX(series_order) AS furthest, MIN(id) AS first_id')
            ->orderBy('first_id')
            ->get();

        $lists = $furthest
            ->map(fn ($row) => $this->querySeriesBookIds(
                (int) $row->series_id,
                $row->furthest === null ? null : (int) $row->furthest,
                $excludeIds,
                $limit,
            ))
            ->filter()
            ->values();

        $picked = [];
        for ($round = 0; count($picked) < $limit && $lists->contains(fn ($list) => isset($list[$round])); $round++) {
            foreach ($lists as $list) {
                if (isset($list[$round]) && count($picked) < $limit) {
                    $picked[] = $list[$round];
                }
            }
        }

        return $picked;
    }

    /**
     * Order statuses that are not a sale for rankings ("best sellers", "bought together"): not placed for real yet,
     * cancelled, or sent back. Deleted orders never count either. Processing and shipped (cash on delivery) do count.
     *
     * @return list<string>
     */
    private static function notSales(): array
    {
        return [OrderStatus::Pending->value, OrderStatus::Cancelled->value, OrderStatus::Returned->value];
    }

    /** Units sold per book in orders that count as sales (see notSales). */
    private function unitsSoldSubquery(): QueryBuilder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('book_variants', 'book_variants.id', '=', 'order_items.book_variant_id')
            ->whereNull('orders.deleted_at')
            ->whereNotIn('orders.status', self::notSales())
            ->groupBy('book_variants.book_id')
            ->selectRaw('book_variants.book_id, SUM(order_items.quantity) AS units_sold');
    }

    /**
     * Find co-purchased book IDs where books were ordered together in >= 2 valid orders.
     *
     * @return list<int>
     */
    private function queryCoPurchasedBookIds(int $targetBookId): array
    {
        return DB::table('order_items as oi2')
            ->join('book_variants as bv2', 'bv2.id', '=', 'oi2.book_variant_id')
            ->whereIn('oi2.order_id', function ($query) use ($targetBookId) {
                $query->select('oi.order_id')
                    ->from('order_items as oi')
                    ->join('book_variants as bv', 'bv.id', '=', 'oi.book_variant_id')
                    ->join('orders as o', 'o.id', '=', 'oi.order_id')
                    ->where('bv.book_id', $targetBookId)
                    ->whereNull('o.deleted_at')
                    ->whereNotIn('o.status', self::notSales());
            })
            ->where('bv2.book_id', '!=', $targetBookId)
            ->groupBy('bv2.book_id')
            ->havingRaw('COUNT(DISTINCT oi2.order_id) >= 2')
            ->orderByRaw('COUNT(DISTINCT oi2.order_id) DESC')
            ->limit(50)
            ->pluck('bv2.book_id')
            ->all();
    }

    /**
     * Filter a list of book IDs, preserving order, to only those that are currently visible and buyable.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function filterInStockAndVisibleIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $activeIds = $this->baseBuyableQuery()
            ->whereIn('books.id', $ids)
            ->pluck('books.id')
            ->all();

        $activeLookup = array_flip($activeIds);

        return array_values(array_filter($ids, fn ($id) => isset($activeLookup[$id])));
    }

    /**
     * Query best sellers in given categories.
     *
     * @param  list<int>  $categoryIds
     * @param  list<int>  $excludeIds
     * @return list<int>
     */
    private function queryCategoryBestSellers(array $categoryIds, array $excludeIds, int $limit): array
    {
        return $this->baseBuyableQuery()
            ->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $categoryIds))
            ->whereNotIn('books.id', $excludeIds)
            ->leftJoinSub($this->unitsSoldSubquery(), 'cat_sales', 'cat_sales.book_id', '=', 'books.id')
            ->withAvg('approvedReviews as rating_avg', 'rating')
            ->orderByRaw('cat_sales.units_sold DESC NULLS LAST')
            ->orderByRaw('rating_avg DESC NULLS LAST')
            ->orderByDesc('books.id')
            ->limit($limit)
            ->pluck('books.id')
            ->all();
    }

    /**
     * Base query for books that are visible and currently buyable (has in-stock physical or digital variant).
     */
    private function baseBuyableQuery(): Builder
    {
        return Book::query()
            ->visible()
            ->whereHas('activeVariants', fn ($v) => $v->where(fn ($w) => $w
                ->where('stock_quantity', '>', 0)
                ->orWhereIn('format', [BookFormat::Ebook->value, BookFormat::Audiobook->value])));
    }
}

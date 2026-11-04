<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookDetailResource;
use App\Http\Resources\BookResource;
use App\Models\Book;
use App\Models\Category;
use App\Services\Catalog\RecommendationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BookController extends Controller
{
    public const SORTS = ['relevance', 'newest', 'price_asc', 'price_desc', 'title', 'rating', 'best_selling', 'most_reviewed'];

    /** "Best selling" counts copies in orders placed in this many days (cancelled orders don't count). */
    public const BEST_SELLING_DAYS = 90;

    public function index(Request $request): AnonymousResourceCollection
    {
        if ($request->has('ids')) {
            $raw = $request->query('ids');
            $ids = is_array($raw) ? $raw : explode(',', (string) $raw);
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
            $ids = array_slice($ids, 0, 12);

            if (empty($ids)) {
                return BookResource::collection(new LengthAwarePaginator([], 0, 12, 1));
            }

            $books = $this->catalogQuery()
                ->whereIn('books.id', $ids)
                ->get();

            $ordered = collect($ids)
                ->map(fn ($id) => $books->firstWhere('id', $id))
                ->filter()
                ->values();

            $paginator = new LengthAwarePaginator($ordered, $ordered->count(), max(1, $ordered->count()), 1);

            return BookResource::collection($paginator);
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'category_id' => ['nullable', 'integer'],
            'category_ids' => ['nullable', 'array', 'max:20'],
            'category_ids.*' => ['integer'],
            'formats' => ['nullable', 'array', 'max:5'],
            'formats.*' => [Rule::enum(BookFormat::class)],
            'age' => ['nullable', 'integer', 'min:0', 'max:18'],
            'school_grade' => ['nullable', 'integer', 'min:1', 'max:12'],
            'level' => ['nullable', 'string', Rule::in(Book::LEVELS)],
            'has_ebook' => ['nullable', 'boolean'],
            'facets' => ['nullable', 'boolean'],
            'author_id' => ['nullable', 'integer'],
            'series_id' => ['nullable', 'integer'],
            'publisher_id' => ['nullable', 'integer'],
            'format' => ['nullable', Rule::enum(BookFormat::class)],
            'language' => ['nullable', 'string', 'max:50'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0', Rule::when($request->filled('min_price'), 'gte:min_price')],
            'in_stock' => ['nullable', 'boolean'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $term = trim($filters['q'] ?? '');
        $sort = $filters['sort'] ?? ($term !== '' ? 'relevance' : 'newest');

        $categoryIds = Category::withDescendants(array_values(array_unique(array_filter(array_map('intval', [
            ...(isset($filters['category_id']) ? [$filters['category_id']] : []), ...($filters['category_ids'] ?? []),
        ]), fn ($id) => $id > 0))));

        $filter = fn (Builder $q): Builder => $q
            // A category covers its sub-categories ("Fiction" shows Fantasy books too); several categories = any of them.
            ->when($categoryIds !== [], fn (Builder $q) => $q->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $categoryIds)))
            ->when($filters['formats'] ?? null, fn (Builder $q, $formats) => $q->whereHas('activeVariants', fn ($v) => $v->whereIn('format', $formats)))
            ->when(isset($filters['age']), fn (Builder $q) => $q->where('age_min', '<=', $filters['age'])->where(fn ($w) => $w->whereNull('age_max')->orWhere('age_max', '>=', $filters['age'])))
            ->when($filters['school_grade'] ?? null, fn (Builder $q, $grade) => $q->where('school_grade', $grade))
            ->when($filters['level'] ?? null, fn (Builder $q, $level) => $q->where('level', $level))
            ->when($request->boolean('has_ebook'), fn (Builder $q) => $q->whereHas('activeVariants', fn ($v) => $v->where('format', BookFormat::Ebook->value)))
            ->when($filters['author_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('authors', fn ($a) => $a->whereKey($id)))
            ->when($filters['series_id'] ?? null, fn (Builder $q, $id) => $q->where('series_id', $id))
            ->when($filters['publisher_id'] ?? null, fn (Builder $q, $id) => $q->where('publisher_id', $id))
            ->when($filters['language'] ?? null, fn (Builder $q, $lang) => $q->where('language', 'ilike', addcslashes($lang, '%_')))
            ->when($filters['format'] ?? null, fn (Builder $q, $format) => $q->whereHas('activeVariants', fn ($v) => $v->where('format', $format)))
            ->when(isset($filters['min_price']), fn (Builder $q) => $q->whereHas('activeVariants', fn ($v) => $v->where('price_usd', '>=', $filters['min_price'])))
            ->when(isset($filters['max_price']), fn (Builder $q) => $q->whereHas('activeVariants', fn ($v) => $v->where('price_usd', '<=', $filters['max_price'])))
            ->when($request->boolean('in_stock'), fn (Builder $q) => $q->whereHas('activeVariants', fn ($v) => $v->where(fn ($w) => $w
                ->where('stock_quantity', '>', 0)
                ->orWhereIn('format', [BookFormat::Ebook->value, BookFormat::Audiobook->value]))));

        $page = function (Builder $query, callable $byRelevance) use ($sort, $filters) {
            match ($sort) {
                'relevance' => $byRelevance($query),
                'price_asc' => $query->orderBy('min_price'),
                'price_desc' => $query->orderByDesc('min_price'),
                'title' => $query->orderBy('title'),
                'rating' => $query->orderByRaw('rating_avg DESC NULLS LAST'),
                'most_reviewed' => $query->orderByDesc('review_count'),
                'best_selling' => $query->orderByDesc(self::copiesSold()),
                default => $query->latest(),
            };

            return $query->orderByDesc('books.id')->paginate($filters['per_page'] ?? 20)->withQueryString();
        };

        $books = $page(
            $filter($this->catalogQuery()->when($term !== '', fn (Builder $q) => $q->search($term))),
            fn (Builder $q) => $term !== '' ? $q->orderByRelevance($term) : $q->latest(),
        );

        // Nothing found for a search: try again tolerating typos ("frankenstien", "sherlok"), same filters.
        $typo = false;
        if ($term !== '' && $books->total() === 0 && ($words = Book::typoSearchWords($term)) !== []) {
            $books = $page(
                $filter($this->catalogQuery()->typoSearch($words)),
                fn (Builder $q) => $q->orderByTypoSimilarity($words),
            );
            $typo = $books->total() > 0;
        }

        $collection = BookResource::collection($books);
        if ($request->boolean('facets')) {
            $collection->additional(['facets' => $this->facets($filter($this->catalogQuery()->when($term !== '', fn (Builder $q) => $q->search($term))))]);
        }

        return $term !== '' ? $collection->additional(['search' => ['typo_tolerant' => $typo]]) : $collection;
    }

    public function show(int $book): BookDetailResource
    {
        $model = $this->catalogQuery()
            ->with(['categories', 'publisher', 'series'])
            ->findOrFail($book);

        return new BookDetailResource($model);
    }

    public function alsoBought(Request $request, int $book, RecommendationService $recommender): AnonymousResourceCollection
    {
        return $this->related($request, $book, $recommender);
    }

    public function related(Request $request, int $book, RecommendationService $recommender): AnonymousResourceCollection
    {
        $targetBook = Book::query()->visible()->findOrFail($book);
        $limit = max(1, min(12, (int) $request->query('limit', 6)));

        $results = $recommender->getRecommendedIdsForBook($targetBook, $limit);
        $books = $this->catalogQuery()->whereIn('books.id', $results)->get();

        return BookResource::collection(collect($results)->map(fn ($id) => $books->firstWhere('id', $id))->filter()->values());
    }

    public function recommendations(Request $request, RecommendationService $recommender): AnonymousResourceCollection
    {
        $limit = max(1, min(12, (int) $request->query('limit', 6)));

        $cartRaw = $request->query('cart_ids');
        $cartIds = is_array($cartRaw) ? $cartRaw : explode(',', (string) $cartRaw);
        $cartIds = array_values(array_unique(array_filter(array_map('intval', $cartIds), fn ($id) => $id > 0)));

        $excludeRaw = $request->query('exclude_ids');
        $excludeIds = is_array($excludeRaw) ? $excludeRaw : explode(',', (string) $excludeRaw);
        $excludeIds = array_values(array_unique(array_filter(array_map('intval', $excludeIds), fn ($id) => $id > 0)));

        $results = $recommender->getRecommendedIdsForCart($cartIds, $excludeIds, $limit);
        $books = $this->catalogQuery()->whereIn('books.id', $results)->get();

        return BookResource::collection(collect($results)->map(fn ($id) => $books->firstWhere('id', $id))->filter()->values());
    }

    /** Visible books with everything the book cards need, loaded in a fixed number of queries. */
    /** Copies of the book sold in the last BEST_SELLING_DAYS days (a sub-query for ordering). */
    private static function copiesSold(): \Illuminate\Database\Query\Builder
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('book_variants', 'book_variants.id', '=', 'order_items.book_variant_id')
            ->whereColumn('book_variants.book_id', 'books.id')
            ->whereNotIn('orders.status', [OrderStatus::Cancelled->value])
            ->where('orders.placed_at', '>=', now()->subDays(self::BEST_SELLING_DAYS))
            ->selectRaw('COALESCE(SUM(order_items.quantity), 0)');
    }

    /**
     * How many books each choice would show with the other filters kept (so the filter panel can hide empty choices):
     * formats, languages, top-level categories, school grades and English levels, plus how many have a reader age.
     *
     * @return array<string, array<string, int>>
     */
    private function facets(Builder $filtered): array
    {
        $ids = (clone $filtered)->setEagerLoads([])->reorder()->select('books.id');
        $books = DB::table('books')->joinSub($ids, 'f', 'f.id', '=', 'books.id');

        $count = fn ($rows) => $rows->mapWithKeys(fn ($r) => [(string) $r->k => (int) $r->n])->all();

        return [
            'formats' => $count(DB::table('book_variants')->joinSub($ids, 'f', 'f.id', '=', 'book_variants.book_id')
                ->where('book_variants.is_active', true)->groupBy('format')->selectRaw('format AS k, COUNT(DISTINCT book_variants.book_id) AS n')->get()),
            'languages' => $count((clone $books)->groupBy('language')->selectRaw('language AS k, COUNT(*) AS n')->get()),
            'categories' => $count(DB::table('book_categories')->joinSub($ids, 'f', 'f.id', '=', 'book_categories.book_id')
                ->join('categories', 'categories.id', '=', 'book_categories.category_id')
                ->whereNull('categories.deleted_at')
                ->groupByRaw('COALESCE(categories.parent_id, categories.id)')
                ->selectRaw('COALESCE(categories.parent_id, categories.id) AS k, COUNT(DISTINCT book_categories.book_id) AS n')->get()),
            'school_grades' => $count((clone $books)->whereNotNull('school_grade')->groupBy('school_grade')->selectRaw('school_grade AS k, COUNT(*) AS n')->get()),
            'levels' => $count((clone $books)->whereNotNull('level')->groupBy('level')->selectRaw('level AS k, COUNT(*) AS n')->get()),
            // Books with a reader age set (the age filter is only worth showing when there are some).
            'with_age' => (clone $books)->whereNotNull('age_min')->count(),
        ];
    }

    private function catalogQuery(): Builder
    {
        return Book::query()->forCards();
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookDetailResource;
use App\Http\Resources\BookResource;
use App\Models\Book;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BookController extends Controller
{
    public const SORTS = ['relevance', 'newest', 'price_asc', 'price_desc', 'title', 'rating'];

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

        $query = $this->catalogQuery()
            ->when($term !== '', fn (Builder $q) => $q->search($term))
            ->when($filters['category_id'] ?? null, fn (Builder $q, $id) => $q->whereHas('categories', fn ($c) => $c->whereKey($id)))
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

        match ($sort) {
            'relevance' => $term !== '' ? $query->orderByRelevance($term) : $query->latest(),
            'price_asc' => $query->orderBy('min_price'),
            'price_desc' => $query->orderByDesc('min_price'),
            'title' => $query->orderBy('title'),
            'rating' => $query->orderByRaw('rating_avg DESC NULLS LAST'),
            default => $query->latest(),
        };

        return BookResource::collection(
            $query->orderByDesc('books.id')->paginate($filters['per_page'] ?? 20)->withQueryString()
        );
    }

    public function show(int $book): BookDetailResource
    {
        $model = $this->catalogQuery()
            ->with(['categories', 'publisher', 'series'])
            ->findOrFail($book);

        return new BookDetailResource($model);
    }

    public function alsoBought(int $book): AnonymousResourceCollection
    {
        $targetBook = Book::query()->visible()->findOrFail($book);

        $results = Cache::remember("books:{$targetBook->id}:also-bought", 3600, function () use ($targetBook) {
            $coBookIds = DB::table('order_items as oi2')
                ->join('book_variants as bv2', 'bv2.id', '=', 'oi2.book_variant_id')
                ->whereIn('oi2.order_id', function ($query) use ($targetBook) {
                    $query->select('oi.order_id')
                        ->from('order_items as oi')
                        ->join('book_variants as bv', 'bv.id', '=', 'oi.book_variant_id')
                        ->join('orders as o', 'o.id', '=', 'oi.order_id')
                        ->where('bv.book_id', $targetBook->id)
                        ->whereNull('o.deleted_at')
                        ->whereNotIn('o.status', [OrderStatus::Pending->value, OrderStatus::Cancelled->value]);
                })
                ->where('bv2.book_id', '!=', $targetBook->id)
                ->groupBy('bv2.book_id')
                ->havingRaw('COUNT(DISTINCT oi2.order_id) >= 2')
                ->orderByRaw('COUNT(DISTINCT oi2.order_id) DESC')
                ->limit(6)
                ->pluck('bv2.book_id')
                ->all();

            $alsoBought = collect();

            if (! empty($coBookIds)) {
                $books = $this->catalogQuery()
                    ->whereHas('activeVariants', fn ($v) => $v->where(fn ($w) => $w
                        ->where('stock_quantity', '>', 0)
                        ->orWhereIn('format', [BookFormat::Ebook->value, BookFormat::Audiobook->value])))
                    ->whereIn('books.id', $coBookIds)
                    ->get();

                $alsoBought = collect($coBookIds)
                    ->map(fn ($id) => $books->firstWhere('id', $id))
                    ->filter()
                    ->values();
            }

            if ($alsoBought->count() < 3) {
                $categoryIds = $targetBook->categories()->pluck('categories.id')->all();

                if (! empty($categoryIds)) {
                    $excludeIds = array_merge([$targetBook->id], $alsoBought->pluck('id')->all());
                    $needed = 6 - $alsoBought->count();

                    $salesSubquery = DB::table('order_items')
                        ->join('orders', 'orders.id', '=', 'order_items.order_id')
                        ->join('book_variants', 'book_variants.id', '=', 'order_items.book_variant_id')
                        ->whereNull('orders.deleted_at')
                        ->whereNotIn('orders.status', [OrderStatus::Pending->value, OrderStatus::Cancelled->value])
                        ->groupBy('book_variants.book_id')
                        ->selectRaw('book_variants.book_id, SUM(order_items.quantity) AS units_sold');

                    $fillers = Book::query()
                        ->visible()
                        ->whereHas('categories', fn ($c) => $c->whereIn('categories.id', $categoryIds))
                        ->whereNotIn('books.id', $excludeIds)
                        ->whereHas('activeVariants', fn ($v) => $v->where(fn ($w) => $w
                            ->where('stock_quantity', '>', 0)
                            ->orWhereIn('format', [BookFormat::Ebook->value, BookFormat::Audiobook->value])))
                        ->leftJoinSub($salesSubquery, 'cat_sales', 'cat_sales.book_id', '=', 'books.id')
                        ->with(['authors', 'activeVariants'])
                        ->withMin('activeVariants as min_price', 'price_usd')
                        ->withAvg('approvedReviews as rating_avg', 'rating')
                        ->withCount('approvedReviews as review_count')
                        ->orderByDesc('cat_sales.units_sold')
                        ->orderByDesc('books.id')
                        ->limit($needed)
                        ->get();

                    $alsoBought = $alsoBought->concat($fillers)->take(6);
                }
            }

            return $alsoBought;
        });

        return BookResource::collection($results);
    }

    /** Visible books with everything the book cards need, loaded in a fixed number of queries. */
    private function catalogQuery(): Builder
    {
        return Book::query()
            ->visible()
            ->with(['authors', 'activeVariants'])
            ->withMin('activeVariants as min_price', 'price_usd')
            ->withAvg('approvedReviews as rating_avg', 'rating')
            ->withCount('approvedReviews as review_count');
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Enums\BookFormat;
use App\Http\Controllers\Controller;
use App\Http\Resources\BookDetailResource;
use App\Http\Resources\BookResource;
use App\Models\Book;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class BookController extends Controller
{
    public const SORTS = ['relevance', 'newest', 'price_asc', 'price_desc', 'title', 'rating'];

    public function index(Request $request): AnonymousResourceCollection
    {
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

<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\CollectionResource;
use App\Models\Book;
use App\Models\BookCollection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Seasonal collections in the shop (owner 2026-11-01): the home page shows up to 2 live ones (position, then id), each
 * with up to 12 books; /collections/{slug} shows one with all its books. Only books that are visible count: hidden ones
 * are left out, and a collection with no visible book is not shown.
 */
class CollectionController extends Controller
{
    public const HOME_LIMIT = 2;

    public const HOME_BOOKS = 12;

    public const PAGE_BOOKS = 60;

    public function live(): AnonymousResourceCollection
    {
        $collections = BookCollection::query()->live()
            ->whereHas('books', fn ($q) => $q->visible())
            ->withCount(['books as visible_books_count' => fn ($q) => $q->visible()])
            ->orderBy('position')->orderBy('id')
            ->limit(self::HOME_LIMIT)
            ->get();
        $this->attachBooks($collections, self::HOME_BOOKS);

        return CollectionResource::collection($collections);
    }

    public function show(string $slug): CollectionResource
    {
        $collection = BookCollection::query()->live()->where('slug', $slug)
            ->whereHas('books', fn ($q) => $q->visible())
            ->withCount(['books as visible_books_count' => fn ($q) => $q->visible()])
            ->firstOrFail();
        $this->attachBooks(new EloquentCollection([$collection]), self::PAGE_BOOKS);

        return new CollectionResource($collection);
    }

    /** The visible books of each collection, in the admin's order, as card data: one query for all of them. */
    private function attachBooks(EloquentCollection $collections, int $limit): void
    {
        if ($collections->isEmpty()) {
            return;
        }
        $order = DB::table('collection_books')->whereIn('collection_id', $collections->modelKeys())
            ->orderBy('position')->orderBy('book_id')->get(['collection_id', 'book_id'])->groupBy('collection_id');
        $books = Book::query()->forCards()->whereIn('books.id', $order->flatten()->pluck('book_id')->unique())->get()->keyBy('id');

        foreach ($collections as $collection) {
            $cards = ($order[$collection->id] ?? collect())
                ->map(fn ($row) => $books[$row->book_id] ?? null)->filter()->take($limit)->values();
            $collection->setRelation('cardBooks', new EloquentCollection($cards->all()));
        }
    }
}

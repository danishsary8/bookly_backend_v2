<?php

namespace App\Services\Catalog;

use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CatalogRows
{
    public function rows(?int $categoryId = null): array
    {
        // Cache ids and totals only: visibility, prices and inventory are read fresh below.
        $rows = Cache::remember('catalog:rows:'.($categoryId ?? 'all'), 900, function () use ($categoryId) {
            $base = Book::query()->visible();
            if ($categoryId !== null) {
                $ids = Category::withDescendants([$categoryId]);
                $base->whereHas('categories', fn ($q) => $q->whereIn('categories.id', $ids));
            }
            $sales = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
                ->join('book_variants as v', 'v.id', '=', 'i.book_variant_id')
                ->whereColumn('v.book_id', 'books.id')->whereNull('o.deleted_at')
                ->where('o.status', '!=', 'cancelled')->where('o.placed_at', '>=', now()->subDays(30))
                ->selectRaw('COALESCE(SUM(i.quantity), 0)');
            $queries = ['best_sellers' => (clone $base)->where($sales, '>', 0)->orderByDesc(clone $sales)];
            if ($categoryId === null) {
                $queries += [
                    'new_arrivals' => (clone $base)->latest(),
                    'coming_soon' => (clone $base)->whereHas('activeVariants', fn ($v) => $v->where('release_date', '>', today()))
                        ->orderBy(BookVariant::query()->selectRaw('MIN(release_date)')->whereColumn('book_id', 'books.id')->where('is_active', true)->where('release_date', '>', today())),
                    'ebook_deals' => (clone $base)->whereHas('activeVariants', fn ($v) => $v->where('format', 'ebook')->where('price_usd', '<', 5))
                        ->orderBy(BookVariant::query()->select('price_usd')->whereColumn('book_id', 'books.id')->where('is_active', true)->where('format', 'ebook')->orderBy('price_usd')->limit(1)),
                ];
            }

            return collect($queries)->map(fn (Builder $q) => [
                'total' => (clone $q)->reorder()->count(),
                'ids' => $q->orderByDesc('books.id')->limit(12)->pluck('books.id')->all(),
            ])->filter(fn ($row) => $row['total'] > 0)->all();
        });

        $books = Book::query()->forCards()->whereIn('books.id', collect($rows)->pluck('ids')->flatten()->unique())->get()->keyBy('id');

        return collect($rows)->map(fn ($row, $id) => [
            'id' => $id,
            'total' => $row['total'],
            'books' => collect($row['ids'])->map(fn ($id) => $books->get($id))->filter()->values(),
        ])->filter(fn ($row) => $row['books']->isNotEmpty())->values()->all();
    }
}

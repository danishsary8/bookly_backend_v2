<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * How one book sells (Phase 4, Book details): copies in the last 30 and 90 days, sales, its rank among all books by
 * copies over 90 days, copies per format and the last order. Orders placed, not cancelled, not deleted.
 */
class BookSalesController extends Controller
{
    public function __invoke(int $book): JsonResponse
    {
        $model = Book::withTrashed()->findOrFail($book);
        $now = CarbonImmutable::now();
        $since90 = $now->subDays(90);
        $since30 = $now->subDays(30);

        $base = fn () => DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('book_variants', 'book_variants.id', '=', 'order_items.book_variant_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value);

        $ranking = $base()->where('orders.placed_at', '>=', $since90)
            ->groupBy('book_variants.book_id')
            ->selectRaw('book_variants.book_id, SUM(order_items.quantity) AS units')
            ->orderByDesc('units')->orderBy('book_variants.book_id')
            ->pluck('units', 'book_id');
        $position = $ranking->keys()->search($model->id);

        $mine = $base()->where('book_variants.book_id', $model->id);
        $totals = (clone $mine)->selectRaw('COALESCE(SUM(CASE WHEN orders.placed_at >= ? THEN order_items.quantity ELSE 0 END), 0) AS sold_30, COALESCE(SUM(CASE WHEN orders.placed_at >= ? THEN order_items.quantity ELSE 0 END), 0) AS sold_90, COALESCE(SUM(CASE WHEN orders.placed_at >= ? THEN order_items.subtotal - order_items.discount_amount ELSE 0 END), 0) AS sales_90, COALESCE(SUM(order_items.quantity), 0) AS sold_all, MAX(orders.placed_at) AS last_sold_at', [$since30, $since90, $since90])->first();
        $formats = (clone $mine)->where('orders.placed_at', '>=', $since90)
            ->groupBy('book_variants.id', 'book_variants.format')
            ->selectRaw('book_variants.id, book_variants.format, SUM(order_items.quantity) AS units')
            ->orderByDesc('units')->get()
            ->map(fn ($r) => ['variant_id' => (int) $r->id, 'format' => $r->format, 'units_sold' => (int) $r->units])->all();

        return response()->json(['data' => [
            'sold_30_days' => (int) $totals->sold_30,
            'sold_90_days' => (int) $totals->sold_90,
            'sales_90_days_usd' => Money::format(Money::toCents($totals->sales_90)),
            'sold_all_time' => (int) $totals->sold_all,
            'rank_90_days' => $position === false ? null : $position + 1,
            'books_ranked' => $ranking->count(),
            'last_sold_at' => $totals->last_sold_at ? CarbonImmutable::parse($totals->last_sold_at)->toIso8601ZuluString() : null,
            'by_format' => $formats,
        ]]);
    }
}

<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** One aggregate query per hour, rather than one sales query per card. */
class SalesSignals
{
    public function count(int $bookId): ?int
    {
        $counts = Cache::remember('catalog:sold-90-days', 3600, fn () => DB::table('order_items as i')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->join('book_variants as v', 'v.id', '=', 'i.book_variant_id')
            ->whereNull('o.deleted_at')
            ->where('o.status', '!=', 'cancelled')
            ->where('o.placed_at', '>=', now()->subDays(90))
            ->groupBy('v.book_id')->selectRaw('v.book_id, SUM(i.quantity) as copies')
            ->pluck('copies', 'book_id')->all());

        $count = (int) ($counts[$bookId] ?? 0);

        return $count >= 5 ? $count : null;
    }
}

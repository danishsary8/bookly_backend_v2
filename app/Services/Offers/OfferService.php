<?php

namespace App\Services\Offers;

use App\Models\Offer;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reads the offers that can apply, for the cart, checkout and the book lists (spec 4.1 and 6.6).
 * ONE query (offers + targets + the targets' trash state and names) cached for 60 seconds; the dates and the
 * use limit are checked again on every read, so a cached offer stops at its end time and a scheduled one starts
 * on time. Admin saves clear the cache (forget()).
 */
class OfferService
{
    public const CACHE_KEY = 'offers:live';

    public const CACHE_SECONDS = 60;

    /** @return list<LiveOffer> offers live right now, in id order */
    public function live(): array
    {
        $now = now()->getTimestamp();

        return array_values(array_filter($this->cached(), fn (LiveOffer $offer) => $offer->isLiveAt($now)));
    }

    /**
     * Same as live(), but read from the database now (no cache, the cache is not touched). Checkout uses it inside its
     * transaction so a use limit reached a second ago is already seen.
     *
     * @return list<LiveOffer>
     */
    public function liveFresh(): array
    {
        $now = now()->getTimestamp();

        return array_values(array_filter($this->load(), fn (LiveOffer $offer) => $offer->isLiveAt($now)));
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return list<LiveOffer> active, not ended, not used up (still includes scheduled ones and "No books") */
    private function cached(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn () => $this->load());
    }

    /** @return list<LiveOffer> */
    private function load(): array
    {
        $rows = DB::table('offers as o')
            ->leftJoin('offer_targets as t', 't.offer_id', '=', 'o.id')
            ->leftJoin('books as b', 'b.id', '=', 't.book_id')
            ->leftJoin('categories as c', 'c.id', '=', 't.category_id')
            ->leftJoin('series as s', 's.id', '=', 't.series_id')
            ->where('o.is_active', true)
            ->where('o.type', Offer::TYPE_BUY_N_PERCENT)
            ->where(fn ($q) => $q->whereNull('o.ends_at')->orWhere('o.ends_at', '>', now()))
            ->where(fn ($q) => $q->whereNull('o.max_orders')->orWhereColumn('o.used_count', '<', 'o.max_orders'))
            ->orderBy('o.id')
            ->orderBy('t.id')
            ->get([
                'o.id', 'o.name', 'o.applies_to', 'o.min_quantity', 'o.percent_off', 'o.starts_at', 'o.ends_at',
                'o.max_orders', 'o.used_count',
                't.book_id', 't.category_id', 't.series_id',
                'b.deleted_at as book_deleted_at',
                'c.name as category_name', 'c.deleted_at as category_deleted_at',
                's.name as series_name', 's.deleted_at as series_deleted_at',
            ]);

        return $rows->groupBy('id')->map(fn ($group) => $this->toLiveOffer($group))->values()->all();
    }

    private function toLiveOffer($group): LiveOffer
    {
        $first = $group->first();
        $all = $first->applies_to === Offer::APPLIES_TO_ALL;
        $books = $categories = $series = [];
        $labels = [];

        if (! $all) {
            // A target in the trash is not for sale, so it is left out (spec 4.10 "No books").
            foreach ($group as $row) {
                if ($row->book_id !== null && $row->book_deleted_at === null) {
                    $books[] = (int) $row->book_id;
                    $labels[] = null;
                } elseif ($row->category_id !== null && $row->category_deleted_at === null) {
                    $categories[] = (int) $row->category_id;
                    $labels[] = $row->category_name;
                } elseif ($row->series_id !== null && $row->series_deleted_at === null) {
                    $series[] = (int) $row->series_id;
                    $labels[] = $row->series_name;
                }
            }
        }

        $startsAt = $first->starts_at !== null ? now()->parse($first->starts_at) : null;
        $endsAt = $first->ends_at !== null ? now()->parse($first->ends_at) : null;

        return new LiveOffer(
            id: (int) $first->id,
            name: $first->name,
            appliesToAll: $all,
            minQuantity: (int) $first->min_quantity,
            percentOff: Money::format(Money::toCents($first->percent_off)),
            percentBasisPoints: Money::toCents($first->percent_off),
            startsAt: $startsAt?->getTimestamp(),
            endsAt: $endsAt?->getTimestamp(),
            endsAtIso: $endsAt?->toIso8601ZuluString(),
            maxOrders: $first->max_orders !== null ? (int) $first->max_orders : null,
            usedCount: (int) $first->used_count,
            bookIds: $books,
            categoryIds: $categories,
            seriesIds: $series,
            // Spec 6.1: a name only when the offer has exactly one target and it is a series or a category.
            targetLabel: count($labels) === 1 ? $labels[0] : null,
        );
    }
}

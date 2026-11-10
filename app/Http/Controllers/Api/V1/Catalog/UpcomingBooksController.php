<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookResource;
use App\Models\Book;
use App\Services\Offers\OfferService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /catalog/upcoming: the storefront "Coming soon" row. Two kinds of book, soonest first (max 12):
 * - pre-orders: visible books with an active format whose release_date is in the future;
 * - announcements: books scheduled to publish later (status "scheduled", publish_at in the future). They are not
 *   public yet, so the card shows no detail link (`announced: true`) and nothing can be bought until they publish.
 * Each book carries `coming_on` (Y-m-d): the earlier of its first future release date and its publish date.
 */
class UpcomingBooksController extends Controller
{
    private const LIMIT = 12;

    public function __invoke(Request $request): JsonResponse
    {
        $now = now();
        $today = today();

        $books = Book::query()
            ->with(['authors', 'activeVariants', ...app(OfferService::class)->bookEagerLoads()])
            ->withMin('activeVariants as min_price', 'price_usd')
            ->withAvg('approvedReviews as rating_avg', 'rating')
            ->withCount('approvedReviews as review_count')
            ->whereHas('variants', fn (Builder $v) => $v->where('is_active', true))
            ->where(function (Builder $q) use ($now, $today) {
                $q->where(fn (Builder $pre) => $pre
                    ->where(fn (Builder $live) => $live->where('status', 'live')
                        ->orWhere(fn (Builder $due) => $due->where('status', 'scheduled')->where('publish_at', '<=', $now)))
                    ->whereHas('activeVariants', fn (Builder $v) => $v->where('release_date', '>', $today)))
                    ->orWhere(fn (Builder $later) => $later->where('status', 'scheduled')->where('publish_at', '>', $now));
            })
            ->orderByRaw(
                "LEAST(
                    COALESCE((SELECT MIN(v.release_date) FROM book_variants v WHERE v.book_id = books.id AND v.is_active = true AND v.release_date > CURRENT_DATE), 'infinity'::date),
                    CASE WHEN books.status = 'scheduled' AND books.publish_at > NOW() THEN books.publish_at::date ELSE 'infinity'::date END
                ) ASC"
            )
            ->orderBy('books.id')
            ->limit(self::LIMIT)
            ->get();

        return response()->json(['data' => $books->map(function (Book $book) use ($request, $now, $today) {
            $announced = $book->status === 'scheduled' && $book->publish_at !== null && $book->publish_at->gt($now);
            $release = $book->activeVariants->pluck('release_date')->filter(fn ($d) => $d !== null && $d->gt($today))->min();
            $dates = array_filter([$release, $announced ? $book->publish_at->copy()->startOfDay() : null]);

            return [
                ...(new BookResource($book))->toArray($request),
                'coming_on' => $dates === [] ? null : min($dates)->toDateString(),
                'announced' => $announced,
            ];
        })->values()]);
    }
}

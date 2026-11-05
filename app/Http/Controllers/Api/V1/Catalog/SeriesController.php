<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\SeriesResource;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Series;
use App\Services\Offers\OfferService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SeriesController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return SeriesResource::collection(
            Series::query()
                ->withCount(['books' => fn ($q) => $q->visible()])
                ->orderBy('name')
                ->paginate($data['per_page'] ?? 20)
                ->withQueryString()
        );
    }

    /** A series with its visible books in reading order, including ownership/in-cart flags for signed-in customer. */
    public function show(Request $request, Series $series): SeriesResource
    {
        $customer = auth('sanctum')->user();
        if (! ($customer instanceof Customer)) {
            $customer = null;
        }

        $ownedBookIds = [];
        $inCartBookIds = [];

        if ($customer) {
            $ownedBookIds = Order::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', [
                    OrderStatus::Paid->value,
                    OrderStatus::Processing->value,
                    OrderStatus::Shipped->value,
                    OrderStatus::Delivered->value,
                ])
                ->join('order_items', 'order_items.order_id', '=', 'orders.id')
                ->join('book_variants', 'book_variants.id', '=', 'order_items.book_variant_id')
                ->distinct()
                ->pluck('book_variants.book_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $cart = Cart::where('customer_id', $customer->id)->first();
            if ($cart) {
                $inCartBookIds = $cart->items()
                    ->join('book_variants', 'book_variants.id', '=', 'cart_items.book_variant_id')
                    ->distinct()
                    ->pluck('book_variants.book_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
        }

        $series->load(['books' => fn ($q) => $q
            ->visible()
            ->with(['authors', 'activeVariants', ...app(OfferService::class)->bookEagerLoads()])
            ->withMin('activeVariants as min_price', 'price_usd')
            ->withAvg('approvedReviews as rating_avg', 'rating')
            ->withCount('approvedReviews as review_count')
            ->orderByRaw('series_order ASC NULLS LAST')
            ->orderBy('id'),
        ]);

        foreach ($series->books as $book) {
            $book->is_owned = in_array($book->id, $ownedBookIds, true);
            $book->is_in_cart = in_array($book->id, $inCartBookIds, true);
        }

        return new SeriesResource($series);
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Models\Order;
use App\Models\Series;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function show(Request $request): CartResource
    {
        return new CartResource($this->carts->summary($request->user()));
    }

    public function addItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'book_variant_id' => ['required', 'integer', 'exists:book_variants,id'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:'.CartService::MAX_PER_LINE],
        ]);

        $this->carts->add($request->user(), $data['book_variant_id'], $data['quantity'] ?? 1);

        return (new CartResource($this->carts->summary($request->user())))->response()->setStatusCode(201);
    }

    public function updateItem(Request $request, int $item): CartResource
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:'.CartService::MAX_PER_LINE]]);

        $this->carts->setQuantity($request->user(), $item, $data['quantity']);

        return new CartResource($this->carts->summary($request->user()));
    }

    public function removeItem(Request $request, int $item): CartResource
    {
        $this->carts->remove($request->user(), $item);

        return new CartResource($this->carts->summary($request->user()));
    }

    public function clear(Request $request): CartResource
    {
        $this->carts->clear($request->user());

        return new CartResource($this->carts->summary($request->user()));
    }

    /** Add missing volumes of a series to cart (complete the set). */
    public function addSeries(Request $request, Series $series): JsonResponse
    {
        $customer = $request->user();

        // 1. Visible books in series ordered by series_order
        $books = $series->books()
            ->visible()
            ->with(['activeVariants' => fn ($q) => $q->where('is_active', true)])
            ->orderByRaw('series_order ASC NULLS LAST')
            ->orderBy('id')
            ->get();

        // 2. Owned book IDs
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

        // 3. In-cart book IDs
        $cart = $this->carts->cartFor($customer);
        $inCartBookIds = $cart->items()
            ->join('book_variants', 'book_variants.id', '=', 'cart_items.book_variant_id')
            ->distinct()
            ->pluck('book_variants.book_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $added = [];
        $skipped = [];

        // `whole_set` (set offers): also add the volumes the customer already owns, so the cart holds the full set.
        $wholeSet = $request->boolean('whole_set');

        foreach ($books as $book) {
            if (! $wholeSet && in_array($book->id, $ownedBookIds, true)) {
                continue;
            }
            if (in_array($book->id, $inCartBookIds, true)) {
                continue;
            }

            // Cheapest physical format in stock
            $variant = $book->activeVariants
                ->filter(fn ($v) => ! $v->format->isDigital() && $v->isAvailable())
                ->sortBy('price_usd')
                ->first();

            if (! $variant) {
                $skipped[] = [
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'series_order' => $book->series_order,
                    'reason' => 'out_of_stock',
                ];

                continue;
            }

            try {
                $this->carts->add($customer, $variant->id, 1);
                $added[] = [
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'series_order' => $book->series_order,
                    'variant_id' => $variant->id,
                    'format' => $variant->format->value,
                    'price_usd' => (string) $variant->price_usd,
                ];
                $inCartBookIds[] = $book->id;
            } catch (\Throwable $e) {
                $skipped[] = [
                    'book_id' => $book->id,
                    'title' => $book->title,
                    'series_order' => $book->series_order,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        $count = count($added);
        $message = match ($count) {
            0 => 'No missing volumes were added.',
            1 => 'Added 1 volume to your cart.',
            default => "Added {$count} volumes to your cart.",
        };

        return response()->json([
            'data' => [
                'added' => $added,
                'skipped' => $skipped,
                'added_count' => count($added),
                'skipped_count' => count($skipped),
                'message' => $message,
                'cart' => new CartResource($this->carts->summary($customer)),
            ],
        ]);
    }
}

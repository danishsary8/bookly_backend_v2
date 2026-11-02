<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Http\Resources\OrderDocumentResource;
use App\Http\Resources\OrderResource;
use App\Models\BookVariant;
use App\Models\Order;
use App\Services\Cart\CartService;
use App\Services\Orders\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(OrderStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return OrderResource::collection(
            $request->user()->orders()
                ->with(['items.variant.book' => fn ($q) => $q->withTrashed(), 'payments', 'coupon'])
                ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->latest('placed_at')
                ->orderByDesc('id')
                ->paginate($data['per_page'] ?? 20)
                ->withQueryString()
        );
    }

    public function show(Request $request, int $order): OrderResource
    {
        return new OrderResource($this->find($request, $order));
    }

    public function cancel(Request $request, int $order, OrderStatusService $statuses): OrderResource
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $statuses->cancelByCustomer($this->find($request, $order), $data['reason'] ?? null);

        return new OrderResource($this->find($request, $order));
    }

    /**
     * "Buy again": adds each item of a past order to the cart with the same rules as adding it by hand.
     * Items that cannot be bought now (out of stock, no longer sold) are skipped and listed with the reason. When fewer
     * copies are in stock than the order had, what is left is added (owner 2026-11-02) and the line carries a `note`.
     */
    public function reorder(Request $request, int $order, CartService $carts): JsonResponse
    {
        $found = $request->user()->orders()->with('items')->findOrFail($order);
        $added = [];
        $skipped = [];

        foreach ($found->items as $item) {
            if ($item->book_variant_id === null) {
                $skipped[] = ['book_variant_id' => null, 'title' => $item->title, 'reason' => __('messages.item_unavailable')];

                continue;
            }

            try {
                $taken = $carts->addUpTo($request->user(), $item->book_variant_id, $item->quantity);
                $added[] = [
                    'book_variant_id' => $item->book_variant_id,
                    'title' => $item->title,
                    'quantity' => $taken,
                    'requested_quantity' => $item->quantity,
                    // Set only when fewer copies were added than the order had.
                    'note' => $taken < $item->quantity
                        ? __('messages.insufficient_stock_simple', ['count' => BookVariant::whereKey($item->book_variant_id)->value('stock_quantity')])
                        : null,
                ];
            } catch (ValidationException $e) {
                $skipped[] = ['book_variant_id' => $item->book_variant_id, 'title' => $item->title, 'reason' => collect($e->errors())->flatten()->first()];
            }
        }

        return response()->json(['data' => [
            'added' => $added,
            'skipped' => $skipped,
            'cart' => (new CartResource($carts->summary($request->user())))->resolve($request),
        ]]);
    }

    /** The customer's own invoice (printed in the browser); a cancelled order has none. */
    public function invoice(Request $request, int $order): OrderDocumentResource
    {
        $found = $request->user()->orders()->with(Order::documentRelations())->findOrFail($order);
        if ($found->status === OrderStatus::Cancelled) {
            throw ValidationException::withMessages(['order' => 'A cancelled order has no invoice.']);
        }

        return new OrderDocumentResource($found);
    }

    private function find(Request $request, int $order): Order
    {
        return $request->user()->orders()
            ->with(['items.variant.book' => fn ($q) => $q->withTrashed(), 'payments', 'coupon', 'statusHistory'])
            ->findOrFail($order);
    }
}

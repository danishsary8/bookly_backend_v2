<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

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

    private function find(Request $request, int $order): Order
    {
        return $request->user()->orders()
            ->with(['items.variant.book' => fn ($q) => $q->withTrashed(), 'payments', 'coupon', 'statusHistory'])
            ->findOrFail($order);
    }
}

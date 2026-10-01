<?php

namespace App\Http\Controllers\Api\V1\Staff\Orders;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffOrderResource;
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
            'q' => ['nullable', 'string', 'max:190'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $term = isset($data['q']) ? '%'.addcslashes($data['q'], '%_').'%' : null;

        return StaffOrderResource::collection(
            Order::query()
                ->with(['customer', 'items.variant.book' => fn ($q) => $q->withTrashed(), 'payments', 'coupon'])
                ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->when($term, fn ($q) => $q->where(fn ($w) => $w
                    ->where('order_number', 'ilike', $term)
                    ->orWhereHas('customer', fn ($c) => $c->withTrashed()->where('email', 'ilike', $term)->orWhere('name', 'ilike', $term))))
                ->when($data['from'] ?? null, fn ($q, $from) => $q->where('placed_at', '>=', $from))
                ->when($data['to'] ?? null, fn ($q, $to) => $q->where('placed_at', '<', \Illuminate\Support\Carbon::parse($to)->addDay()))
                ->latest('placed_at')
                ->orderByDesc('id')
                ->paginate($data['per_page'] ?? 20)
                ->withQueryString()
        );
    }

    public function show(int $order): StaffOrderResource
    {
        return new StaffOrderResource($this->find($order));
    }

    public function updateStatus(Request $request, int $order, OrderStatusService $statuses): StaffOrderResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::enum(OrderStatus::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $statuses->changeByStaff($this->find($order), OrderStatus::from($data['status']), $request->user(), $data['note'] ?? null);

        return new StaffOrderResource($this->find($order));
    }

    private function find(int $order): Order
    {
        return Order::with([
            'customer', 'items.variant.book' => fn ($q) => $q->withTrashed(),
            'payments', 'coupon', 'statusHistory.changedBy',
        ])->findOrFail($order);
    }
}

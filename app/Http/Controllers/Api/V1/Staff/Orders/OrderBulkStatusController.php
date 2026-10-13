<?php

namespace App\Http\Controllers\Api\V1\Staff\Orders;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Orders\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Moves several orders to the same status (e.g. the morning's packing to "shipped"). Each order changes on its own,
 * through the same rules, stock, payment, history, audit and customer email as a single change; an order that can't
 * move stays as it was and is listed with the reason. Cancelling stays one order at a time (owner's rule).
 */
class OrderBulkStatusController extends Controller
{
    public const MAX_ORDERS = 50;

    /** Bulk targets: every staff step except cancel, which gives stock and coupons back. */
    public const TARGETS = [OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered];

    public function __invoke(Request $request, OrderStatusService $statuses): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ORDERS],
            'ids.*' => ['integer', 'min:1', 'distinct'],
            'status' => ['required', Rule::in(array_map(fn (OrderStatus $s) => $s->value, self::TARGETS))],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'status.in' => 'Bulk changes can start processing, mark as shipped or mark as delivered. Cancel orders one at a time.',
        ]);

        $to = OrderStatus::from($data['status']);
        $orders = Order::whereIn('id', $data['ids'])->get()->keyBy('id');
        $updated = [];
        $failed = [];

        foreach (array_map('intval', $data['ids']) as $id) {
            $order = $orders->get($id);
            if (! $order) {
                $failed[] = ['id' => $id, 'order_number' => null, 'reason' => 'Order not found.'];

                continue;
            }

            try {
                $changed = $statuses->changeByStaff($order, $to, $request->user(), $data['note'] ?? null);
                $updated[] = ['id' => $changed->id, 'order_number' => $changed->order_number, 'status' => $changed->status->value];
            } catch (ValidationException $e) {
                $failed[] = ['id' => $id, 'order_number' => $order->order_number, 'reason' => collect($e->errors())->flatten()->first()];
            }
        }

        return response()->json(['data' => ['status' => $to->value, 'updated' => $updated, 'failed' => $failed]]);
    }
}

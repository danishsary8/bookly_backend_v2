<?php

namespace App\Http\Controllers\Api\V1\Staff\Orders;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderDocumentResource;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Print data for invoices and packing slips: one order or a batch (e.g. the morning's packing), in the order asked. */
class OrderDocumentController extends Controller
{
    public const MAX_ORDERS = 50;

    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ORDERS],
            'ids.*' => ['integer', 'min:1', 'distinct'],
        ]);
        $ids = array_map('intval', $data['ids']);

        $orders = Order::with(Order::documentRelations())->whereIn('id', $ids)->get()
            ->sortBy(fn (Order $order) => array_search($order->id, $ids, true))
            ->values();

        return OrderDocumentResource::collection($orders);
    }
}

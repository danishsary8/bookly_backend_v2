<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReturnResource;
use App\Models\OrderReturn;
use App\Services\Returns\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReturnController extends Controller
{
    public function __construct(private readonly ReturnService $returns) {}

    public static function relations(): array
    {
        return ['order', 'items.orderItem.variant.book' => fn ($q) => $q->withTrashed()];
    }

    /** What can still be returned from an order, and until when. */
    public function returnable(Request $request, int $order): JsonResponse
    {
        $model = $request->user()->orders()->findOrFail($order);
        $block = $this->returns->blockReason($model);

        return response()->json(['data' => [
            'order_id' => $model->id,
            'returnable_until' => $this->returns->returnableUntil($model),
            'can_request_return' => $block === null,
            'reason_unavailable' => $block,
            'items' => $this->returns->returnableItems($model)->map(fn ($row) => [
                'order_item_id' => $row['item']->id,
                'title' => $row['item']->variant->book?->title,
                'format' => $row['item']->variant->format->value,
                'purchased_quantity' => $row['item']->quantity,
                'returnable_quantity' => $row['returnable_quantity'],
            ])->values(),
        ]]);
    }

    public function store(Request $request, int $order): JsonResponse
    {
        $model = $request->user()->orders()->findOrFail($order);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.order_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        $return = $this->returns->request($request->user(), $model, $data['reason'], $data['items']);

        return (new ReturnResource($return->load(self::relations())))->response()->setStatusCode(201);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return ReturnResource::collection(
            $request->user()->returns()->with(self::relations())->latest('requested_at')->orderByDesc('id')
                ->paginate($data['per_page'] ?? 20)->withQueryString()
        );
    }

    public function show(Request $request, int $return): ReturnResource
    {
        return new ReturnResource($request->user()->returns()->with(self::relations())->findOrFail($return));
    }

    public function destroy(Request $request, int $return): JsonResponse
    {
        $this->returns->withdraw($request->user()->returns()->findOrFail($return));

        return response()->json(null, 204);
    }
}

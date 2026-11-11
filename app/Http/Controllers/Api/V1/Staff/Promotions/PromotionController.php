<?php

namespace App\Http\Controllers\Api\V1\Staff\Promotions;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Promotion;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Promotion pop-ups (owner 2026-11-12). Staff with catalog:view see them with their results; staff with
 * catalog:manage (and admins) create, change, show/hide and delete them: a promotion only announces, the money part
 * is the linked offer, which stays admin-only. Results: views, clicks, and the linked offer's orders and revenue
 * while the promotion ran.
 */
class PromotionController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $page = Promotion::query()->with('offer:id,name')->orderByDesc('id')->paginate($data['per_page'] ?? 25)->withQueryString();

        return response()->json([
            'data' => $page->getCollection()->map(fn (Promotion $promotion) => $this->shape($promotion))->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Promotion $promotion): JsonResponse
    {
        return response()->json(['data' => $this->shape($promotion->load('offer:id,name'))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(creating: true));
        $this->checkDates($data['starts_at'] ?? null, $data['ends_at'] ?? null);

        $promotion = Promotion::create($data);
        $this->audit->custom($request->user(), 'created', $promotion, null, $promotion->getAttributes());

        return response()->json(['data' => $this->shape($promotion->refresh()->load('offer:id,name'))], 201);
    }

    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $data = $request->validate($this->rules(creating: false));
        $this->checkDates(
            array_key_exists('starts_at', $data) ? $data['starts_at'] : $promotion->starts_at,
            array_key_exists('ends_at', $data) ? $data['ends_at'] : $promotion->ends_at,
        );

        $before = $promotion->getAttributes();
        $promotion->update($data);
        $changed = array_keys($promotion->getChanges());
        if ($changed !== []) {
            $pick = fn (array $row) => array_intersect_key($row, array_flip($changed));
            $this->audit->custom($request->user(), 'updated', $promotion, $pick($before), $pick($promotion->getAttributes()));
        }

        return response()->json(['data' => $this->shape($promotion->refresh()->load('offer:id,name'))]);
    }

    public function destroy(Request $request, Promotion $promotion): JsonResponse
    {
        $before = $promotion->getAttributes();
        $promotion->delete();
        $this->audit->custom($request->user(), 'deleted', $promotion, $before, null);

        return response()->json(null, 204);
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'title_en' => [$required, 'string', 'min:1', 'max:120'],
            'title_km' => ['sometimes', 'nullable', 'string', 'max:120'],
            'body_en' => ['sometimes', 'nullable', 'string', 'max:500'],
            'body_km' => ['sometimes', 'nullable', 'string', 'max:500'],
            'image_url' => ['sometimes', 'nullable', 'url:https', 'max:500'],
            'cta_label_en' => ['sometimes', 'nullable', 'string', 'max:40'],
            'cta_label_km' => ['sometimes', 'nullable', 'string', 'max:40'],
            // A path inside the shop only ("/books?sort=newest"), never another website.
            'cta_url' => ['sometimes', 'string', 'max:255', 'regex:/^\/(?!\/)[^\s]*$/'],
            'offer_id' => ['sometimes', 'nullable', 'integer', 'exists:offers,id'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function checkDates(mixed $startsAt, mixed $endsAt): void
    {
        if ($startsAt !== null && $endsAt !== null && now()->parse($endsAt)->lte(now()->parse($startsAt))) {
            throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
        }
    }

    private function shape(Promotion $promotion): array
    {
        // Results of the linked offer while the promotion ran: orders placed with it (cancelled ones left out).
        $orders = 0;
        $revenue = '0.00';
        if ($promotion->offer_id !== null) {
            $query = Order::query()->where('offer_id', $promotion->offer_id)->where('status', '!=', OrderStatus::Cancelled->value)
                ->when($promotion->starts_at, fn ($q, $from) => $q->where('placed_at', '>=', $from))
                ->when($promotion->ends_at, fn ($q, $to) => $q->where('placed_at', '<', $to));
            $orders = (clone $query)->count();
            $revenue = number_format((float) (clone $query)->sum('total_amount'), 2, '.', '');
        }

        return [
            'id' => $promotion->id,
            'title_en' => $promotion->title_en,
            'title_km' => $promotion->title_km,
            'body_en' => $promotion->body_en,
            'body_km' => $promotion->body_km,
            'image_url' => $promotion->image_url,
            'cta_label_en' => $promotion->cta_label_en,
            'cta_label_km' => $promotion->cta_label_km,
            'cta_url' => $promotion->cta_url,
            'offer_id' => $promotion->offer_id,
            'offer_name' => $promotion->offer?->name,
            'starts_at' => $promotion->starts_at,
            'ends_at' => $promotion->ends_at,
            'is_active' => $promotion->is_active,
            'status' => $promotion->status(),
            'views' => $promotion->views,
            'clicks' => $promotion->clicks,
            'orders_count' => $orders,
            'revenue_usd' => $revenue,
            'created_at' => $promotion->created_at,
            'updated_at' => $promotion->updated_at,
        ];
    }
}

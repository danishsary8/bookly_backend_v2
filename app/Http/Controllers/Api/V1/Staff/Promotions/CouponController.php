<?php

namespace App\Http\Controllers\Api\V1\Staff\Promotions;

use App\Enums\CouponType;
use App\Http\Controllers\Controller;
use App\Http\Resources\CouponResource;
use App\Models\Coupon;
use App\Services\Admin\AuditLogger;
use App\Services\Cart\CouponService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:50'],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return CouponResource::collection(
            Coupon::query()
                ->when($data['q'] ?? null, fn ($q, $term) => $q->where('code', 'ilike', '%'.addcslashes($term, '%_').'%'))
                ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
                ->latest()
                ->orderByDesc('id')
                ->paginate($data['per_page'] ?? 20)
                ->withQueryString()
        );
    }

    public function show(Coupon $coupon): CouponResource
    {
        return new CouponResource($coupon);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['code' => CouponService::normalize((string) $request->input('code'))]);
        $coupon = Coupon::create($request->validate($this->rules($request)));
        $this->audit->created($request->user(), $coupon);

        return (new CouponResource($coupon->refresh()))->response()->setStatusCode(201);
    }

    public function update(Request $request, Coupon $coupon): CouponResource
    {
        if ($request->has('code')) {
            $request->merge(['code' => CouponService::normalize((string) $request->input('code'))]);
        }
        $data = $request->validate($this->rules($request, $coupon));

        $before = $coupon->getAttributes();
        $coupon->update($data);
        $this->audit->updated($request->user(), $coupon, $before);

        return new CouponResource($coupon);
    }

    /** Coupons that were used keep order history readable, so they can only be deactivated. */
    public function destroy(Request $request, Coupon $coupon): JsonResponse
    {
        if ($coupon->used_count > 0 || $coupon->orders()->exists()) {
            return response()->json(['message' => 'This coupon has been used, so it cannot be deleted. Set is_active to false instead.'], 409);
        }

        $coupon->delete();
        $this->audit->deleted($request->user(), $coupon);

        return response()->json(null, 204);
    }

    private function rules(Request $request, ?Coupon $coupon = null): array
    {
        $required = $coupon ? 'sometimes' : 'required';
        $type = $request->input('type', $coupon?->type?->value);

        return [
            'code' => [$required, 'string', 'min:3', 'max:50', 'alpha_dash', Rule::unique('coupons', 'code')->ignore($coupon)],
            'type' => [$required, Rule::enum(CouponType::class)],
            'value' => [$required, 'numeric', 'gt:0', 'decimal:0,2', $type === CouponType::Percentage->value ? 'max:100' : 'max:99999999.99'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', Rule::when($request->filled('starts_at'), 'after:starts_at')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

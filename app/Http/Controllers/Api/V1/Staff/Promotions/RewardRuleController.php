<?php

namespace App\Http\Controllers\Api\V1\Staff\Promotions;

use App\Http\Controllers\Controller;
use App\Models\RewardRule;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Reward rules ("buy 2 books, get 10% off your next order", owner 2026-11-12). Staff with catalog:view see them with
 * their results; only admins create, change or delete them (a reward gives money away, like coupons and offers).
 * The newest active rule is the one the shop uses.
 */
class RewardRuleController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        $rules = RewardRule::query()
            ->withCount(['coupons as issued_count', 'coupons as used_count' => fn ($q) => $q->where('used_count', '>', 0)])
            ->latest('id')
            ->get();

        return response()->json(['data' => $rules->map(fn (RewardRule $rule) => $this->payload($rule))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $rule = RewardRule::create($this->validated($request));
        $this->audit->created($request->user(), $rule);

        return response()->json(['data' => $this->payload($rule->loadCount(['coupons as issued_count']))], 201);
    }

    public function update(Request $request, RewardRule $rule): JsonResponse
    {
        $before = $rule->getAttributes();
        $rule->update($this->validated($request, $rule));
        $this->audit->updated($request->user(), $rule, $before);

        return response()->json(['data' => $this->payload($rule->loadCount(['coupons as issued_count', 'coupons as used_count' => fn ($q) => $q->where('used_count', '>', 0)]))]);
    }

    /** Coupons already given keep working (their rule link is cleared). */
    public function destroy(Request $request, RewardRule $rule): Response
    {
        $this->audit->deleted($request->user(), $rule);
        $rule->delete();

        return response()->noContent();
    }

    private function validated(Request $request, ?RewardRule $rule = null): array
    {
        $sometimes = $rule ? 'sometimes' : 'required';
        $data = $request->validate([
            'name' => [$sometimes, 'string', 'max:120'],
            'is_active' => ['sometimes', 'boolean'],
            'min_books' => ['nullable', 'integer', 'min:1', 'max:100'],
            'min_amount' => ['nullable', 'decimal:0,2', 'min:0.01', 'max:100000'],
            'percent_off' => [$sometimes, 'decimal:0,2', 'min:1', 'max:90'],
            'max_discount' => ['nullable', 'decimal:0,2', 'min:0.01', 'max:100000'],
            'valid_days' => [$sometimes, 'integer', 'min:1', 'max:365'],
        ]);
        $minBooks = array_key_exists('min_books', $data) ? $data['min_books'] : $rule?->min_books;
        $minAmount = array_key_exists('min_amount', $data) ? $data['min_amount'] : $rule?->min_amount;
        if ($minBooks === null && $minAmount === null) {
            throw ValidationException::withMessages(['min_books' => 'Set a number of books, an amount, or both.']);
        }

        return $data;
    }

    private function payload(RewardRule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'is_active' => $rule->is_active,
            'min_books' => $rule->min_books,
            'min_amount_usd' => $rule->min_amount,
            'percent_off' => $rule->percent_off,
            'max_discount_usd' => $rule->max_discount,
            'valid_days' => $rule->valid_days,
            'issued_count' => (int) ($rule->issued_count ?? 0),
            'used_count' => (int) ($rule->used_count ?? 0),
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }
}

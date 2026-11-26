<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Services\Catalog\BulkPriceChange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Admin-only "Change prices": POST /staff/variants/prices/preview shows the result, POST /staff/variants/prices saves it. */
class BulkPriceController extends Controller
{
    public function __construct(private readonly BulkPriceChange $prices) {}

    public function preview(Request $request): JsonResponse
    {
        $rows = $this->prices->plan($this->rule($request));

        return response()->json(['data' => ['items' => $rows->values(), 'changed_count' => $rows->where('changed', true)->count()]]);
    }

    public function apply(Request $request): JsonResponse
    {
        $rows = $this->prices->apply($this->rule($request), $request->user());

        return response()->json(['data' => ['items' => $rows->values(), 'updated_count' => $rows->where('changed', true)->count()]]);
    }

    private function rule(Request $request): array
    {
        abort_unless($request->user()->role === StaffRole::Admin, 403, 'Only an admin can change book prices.');

        $data = $request->validate([
            'variant_ids' => ['required', 'array', 'min:1', 'max:3000'],
            'variant_ids.*' => ['integer', 'distinct'],
            'sell_mode' => ['required', Rule::in(BulkPriceChange::SELL_MODES)],
            'sell_value' => ['nullable', 'numeric', 'min:-100000', 'max:100000'],
            'cost_mode' => ['required', Rule::in(BulkPriceChange::COST_MODES)],
            'cost_value' => ['nullable', 'numeric', 'min:-100000', 'max:100000'],
            'rounding' => ['nullable', Rule::in(BulkPriceChange::ROUNDING)],
            'floor_at_cost' => ['nullable', 'boolean'],
        ]);

        if ($data['sell_mode'] === 'keep' && $data['cost_mode'] === 'keep') {
            throw ValidationException::withMessages(['sell_mode' => 'Choose a change for the sell price or the cost price.']);
        }
        foreach (['sell', 'cost'] as $side) {
            $mode = $data["{$side}_mode"];
            $value = $data["{$side}_value"] ?? null;
            if ($mode !== 'keep' && $value === null) {
                throw ValidationException::withMessages(["{$side}_value" => 'Enter a number for this change.']);
            }
            if (in_array($mode, ['set', 'markup'], true) && $value <= 0) {
                throw ValidationException::withMessages(["{$side}_value" => 'This must be more than 0.']);
            }
            if ($mode === 'percent' && $value <= -100) {
                throw ValidationException::withMessages(["{$side}_value" => 'A cut must be less than 100%.']);
            }
        }

        return [
            'variant_ids' => array_map('intval', $data['variant_ids']),
            'sell_mode' => $data['sell_mode'],
            'sell_value' => isset($data['sell_value']) ? (float) $data['sell_value'] : null,
            'cost_mode' => $data['cost_mode'],
            'cost_value' => isset($data['cost_value']) ? (float) $data['cost_value'] : null,
            'rounding' => $data['rounding'] ?? 'none',
            'floor_at_cost' => (bool) ($data['floor_at_cost'] ?? true),
        ];
    }
}

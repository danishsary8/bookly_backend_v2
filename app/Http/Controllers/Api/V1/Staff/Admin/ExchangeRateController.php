<?php

namespace App\Http\Controllers\Api\V1\Staff\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** USD -> KHR rate, entered by hand. Every rate is kept; the newest one already in effect is used. */
class ExchangeRateController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $current = ExchangeRate::current('USD', 'KHR');
        $page = ExchangeRate::where('base_currency', 'USD')->where('target_currency', 'KHR')
            ->orderByDesc('effective_at')->orderByDesc('id')->paginate($data['per_page'] ?? 20);

        return response()->json([
            'data' => collect($page->items())->map(fn ($r) => $this->present($r, $current)),
            'meta' => [
                'current_rate' => $current?->rate,
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rate' => ['required', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,6'],
            'effective_at' => ['nullable', 'date'],
        ]);

        $rate = ExchangeRate::create([
            'base_currency' => 'USD',
            'target_currency' => 'KHR',
            'rate' => $data['rate'],
            'effective_at' => $data['effective_at'] ?? now(),
        ]);
        $this->audit->created($request->user(), $rate);

        return response()->json(['data' => $this->present($rate->refresh(), ExchangeRate::current('USD', 'KHR'))], 201);
    }

    private function present(ExchangeRate $rate, ?ExchangeRate $current): array
    {
        return [
            'id' => $rate->id,
            'base_currency' => $rate->base_currency,
            'target_currency' => $rate->target_currency,
            'rate' => $rate->rate,
            'effective_at' => $rate->effective_at,
            'is_current' => $current?->is($rate) ?? false,
            'created_at' => $rate->created_at,
        ];
    }
}

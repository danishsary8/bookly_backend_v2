<?php

namespace App\Http\Controllers\Api\V1\Staff\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function summary(Request $request): JsonResponse
    {
        [$start, $end] = $this->period($request);

        return response()->json(['data' => $this->dashboard->summary($start, $end)]);
    }

    public function sales(Request $request): JsonResponse
    {
        [$start, $end] = $this->period($request);

        return response()->json([
            'data' => $this->dashboard->dailySales($start, $end),
            'meta' => ['from' => $start->toDateString(), 'to' => $end->subDay()->toDateString(), 'timezone' => $start->timezoneName],
        ]);
    }

    private function period(Request $request): array
    {
        $data = $request->validate([
            'period' => ['nullable', Rule::in(DashboardService::PERIODS)],
            'from' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return $this->dashboard->period($data['period'] ?? '30d', $data['from'] ?? null, $data['to'] ?? null);
    }
}

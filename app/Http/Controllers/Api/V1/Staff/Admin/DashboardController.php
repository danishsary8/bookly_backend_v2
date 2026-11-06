<?php

namespace App\Http\Controllers\Api\V1\Staff\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\DashboardInsightsService;
use App\Services\Admin\DashboardService;
use App\Services\Admin\PurchaseListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function summary(Request $request): JsonResponse
    {
        [$start, $end] = $this->period($request);
        $compare = $request->validate(['compare' => ['nullable', 'boolean']])['compare'] ?? true;

        return response()->json(['data' => $this->dashboard->summary($start, $end, (bool) $compare)]);
    }

    public function sales(Request $request): JsonResponse
    {
        [$start, $end] = $this->period($request);

        return response()->json([
            'data' => $this->dashboard->dailySales($start, $end),
            'meta' => ['from' => $start->toDateString(), 'to' => $end->subDay()->toDateString(), 'timezone' => $start->timezoneName],
        ]);
    }

    /** Trends, dead stock, stock value, authors, provinces, promotions, repeat customers (spec 2026-11-01, branch 1). */
    public function insights(Request $request, DashboardInsightsService $insights): JsonResponse
    {
        [$start, $end] = $this->period($request);

        return response()->json(['data' => $insights->insights($start, $end)]);
    }

    /** Menu badge counts for every staff member: pending orders, open returns, low stock. */
    public function counts(DashboardInsightsService $insights): JsonResponse
    {
        return response()->json(['data' => $insights->counts()]);
    }

    public function purchases(Request $request, PurchaseListService $purchases): Response
    {
        $data = $request->validate(['format' => ['nullable', Rule::in(['json', 'csv'])]]);
        $rows = $purchases->items();

        if (($data['format'] ?? 'json') === 'csv') {
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, ['Book', 'Format', 'SKU', 'Stock', 'Threshold', 'Waiting', 'Quantity to buy']);
                foreach ($rows as $row) {
                    fputcsv($out, array_map([OrderExportController::class, 'cell'], [
                        $row['title'], $row['format'], $row['sku'], $row['stock_quantity'],
                        $row['low_stock_threshold'], $row['waiting_quantity'], $row['quantity_to_buy'],
                    ]));
                }
                fclose($out);
            }, 'purchase-list.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->json(['data' => $rows]);
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

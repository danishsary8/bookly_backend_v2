<?php

namespace App\Http\Controllers\Api\V1\Staff\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\ReportsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportsController extends Controller
{
    public function __construct(private readonly ReportsService $reports) {}

    public function sales(Request $request): Response
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'group' => ['nullable', Rule::in(['day', 'week', 'month'])],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);

        [$start, $end] = $this->reports->range($data['from'], $data['to']);
        $group = $data['group'] ?? 'day';
        $report = $this->reports->sales($start, $end, $group);

        if (($data['format'] ?? 'json') === 'csv') {
            return $this->streamCsv("report-sales-{$data['from']}-to-{$data['to']}.csv", function ($out) use ($report) {
                fputcsv($out, ['Period / Date', 'Orders Delivered', 'Gross Revenue (USD)', 'Gross Revenue (KHR)', 'Refunds (USD)', 'Refunds (KHR)', 'Net Revenue (USD)', 'Net Revenue (KHR)', 'Items Sold', 'Avg Order Value (USD)']);
                foreach ($report['series'] as $row) {
                    fputcsv($out, array_map([OrderExportController::class, 'cell'], [
                        $row['date'],
                        $row['orders_count'],
                        $row['gross_revenue_usd'],
                        $row['gross_revenue_khr'] ?? '',
                        $row['refunds_usd'],
                        $row['refunds_khr'] ?? '',
                        $row['net_revenue_usd'],
                        $row['net_revenue_khr'] ?? '',
                        $row['items_sold'],
                        $row['average_order_value_usd'],
                    ]));
                }
            });
        }

        return response()->json(['data' => $report]);
    }

    public function orders(Request $request): Response
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);

        [$start, $end] = $this->reports->range($data['from'], $data['to']);
        $report = $this->reports->orders($start, $end);

        if (($data['format'] ?? 'json') === 'csv') {
            return $this->streamCsv("report-orders-{$data['from']}-to-{$data['to']}.csv", function ($out) use ($report) {
                fputcsv($out, ['Metric', 'Value']);
                fputcsv($out, ['Total Orders Placed', $report['total_orders']]);
                fputcsv($out, ['Cancellation Rate (%)', $report['cancellation_rate']]);
                fputcsv($out, ['Avg Delivery Time (Hours)', $report['average_delivery_hours'] ?? 'N/A']);
                fputcsv($out, ['Orders Phnom Penh', $report['orders_by_delivery_area']['phnom_penh']]);
                fputcsv($out, ['Orders Provinces', $report['orders_by_delivery_area']['provinces']]);
                foreach ($report['orders_by_status'] as $status => $count) {
                    fputcsv($out, ["Status: {$status}", $count]);
                }
                foreach ($report['orders_by_payment_method'] as $method => $count) {
                    fputcsv($out, ["Payment: {$method}", $count]);
                }
            });
        }

        return response()->json(['data' => $report]);
    }

    public function products(Request $request): Response
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);

        [$start, $end] = $this->reports->range($data['from'], $data['to']);
        $limit = isset($data['limit']) ? (int) $data['limit'] : 10;
        $report = $this->reports->products($start, $end, $limit);

        if (($data['format'] ?? 'json') === 'csv') {
            return $this->streamCsv("report-products-{$data['from']}-to-{$data['to']}.csv", function ($out) use ($report) {
                fputcsv($out, ['Type', 'Rank', 'Title', 'Format', 'Units Sold', 'Revenue (USD)', 'Revenue (KHR)']);
                foreach ($report['best_sellers_by_revenue'] as $i => $row) {
                    fputcsv($out, array_map([OrderExportController::class, 'cell'], [
                        'Best Seller by Revenue',
                        $i + 1,
                        $row['title'],
                        $row['format'],
                        $row['units_sold'],
                        $row['revenue_usd'],
                        $row['revenue_khr'] ?? '',
                    ]));
                }
                foreach ($report['best_sellers_by_units'] as $i => $row) {
                    fputcsv($out, array_map([OrderExportController::class, 'cell'], [
                        'Best Seller by Units',
                        $i + 1,
                        $row['title'],
                        $row['format'],
                        $row['units_sold'],
                        $row['revenue_usd'],
                        $row['revenue_khr'] ?? '',
                    ]));
                }
            });
        }

        return response()->json(['data' => $report]);
    }

    public function customers(Request $request): Response
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);

        [$start, $end] = $this->reports->range($data['from'], $data['to']);
        $report = $this->reports->customers($start, $end);

        if (($data['format'] ?? 'json') === 'csv') {
            return $this->streamCsv("report-customers-{$data['from']}-to-{$data['to']}.csv", function ($out) use ($report) {
                fputcsv($out, ['Customer ID', 'Name', 'Email', 'Orders Count', 'Total Spent (USD)', 'Total Spent (KHR)']);
                foreach ($report['top_customers'] as $cust) {
                    fputcsv($out, array_map([OrderExportController::class, 'cell'], [
                        $cust['customer_id'],
                        $cust['name'],
                        $cust['email'],
                        $cust['orders_count'],
                        $cust['total_spent_usd'],
                        $cust['total_spent_khr'] ?? '',
                    ]));
                }
            });
        }

        return response()->json(['data' => $report]);
    }

    public function funnel(Request $request): Response
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);

        [$start, $end] = $this->reports->range($data['from'], $data['to']);
        $report = $this->reports->funnel($start, $end);

        if (($data['format'] ?? 'json') === 'csv') {
            return $this->streamCsv("report-funnel-{$data['from']}-to-{$data['to']}.csv", function ($out) use ($report) {
                fputcsv($out, ['Step', 'Count', 'Conversion From First Step (%)', 'Conversion From Previous Step (%)', 'Dropoff (%)']);
                foreach ($report['steps'] as $step) {
                    fputcsv($out, array_map([OrderExportController::class, 'cell'], [
                        $step['name'],
                        $step['count'],
                        $step['conversion_rate'],
                        $step['step_conversion_rate'],
                        $step['dropoff_rate'],
                    ]));
                }
            });
        }

        return response()->json(['data' => $report]);
    }

    public function coupons(Request $request): Response
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['nullable', Rule::in(['json', 'csv'])],
        ]);

        [$start, $end] = $this->reports->range($data['from'], $data['to']);
        $report = $this->reports->coupons($start, $end);

        if (($data['format'] ?? 'json') === 'csv') {
            return $this->streamCsv("report-coupons-{$data['from']}-to-{$data['to']}.csv", function ($out) use ($report) {
                fputcsv($out, ['Section', 'Code / Reason', 'Type / Detail', 'Count / Times Used', 'Amount / Discount (USD)', 'Order Revenue (USD)']);
                foreach ($report['coupons'] as $cpn) {
                    fputcsv($out, array_map([OrderExportController::class, 'cell'], [
                        'Coupon',
                        $cpn['code'],
                        $cpn['type'] === 'percentage' ? "{$cpn['value']}%" : "\${$cpn['value']}",
                        $cpn['times_used'],
                        $cpn['discount_usd'],
                        $cpn['revenue_usd'],
                    ]));
                }
                foreach ($report['refunds']['by_reason'] as $ref) {
                    fputcsv($out, array_map([OrderExportController::class, 'cell'], [
                        'Refund by Reason',
                        $ref['reason'] ?? 'Unspecified',
                        '',
                        $ref['count'],
                        $ref['amount_usd'],
                        '',
                    ]));
                }
            });
        }

        return response()->json(['data' => $report]);
    }

    private function streamCsv(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload(function () use ($writer) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel Khmer support
            $writer($out);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}

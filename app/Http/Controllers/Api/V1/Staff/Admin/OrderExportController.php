<?php

namespace App\Http\Controllers\Api\V1\Staff\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Orders placed in a date range as CSV, for bookkeeping in Excel or Google Sheets. */
class OrderExportController extends Controller
{
    private const MAX_DAYS = 366;

    private const COLUMNS = [
        'order_number', 'placed_at', 'status', 'customer_name', 'customer_email', 'items', 'subtotal_usd',
        'discount_usd', 'shipping_fee_usd', 'tax_usd', 'total_usd', 'coupon_code', 'payment_method', 'payment_status',
        'shipping_city', 'shipping_country',
    ];

    public function __invoke(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $tz = config('shop.timezone');
        $start = CarbonImmutable::parse($data['from'], $tz)->startOfDay();
        $end = CarbonImmutable::parse($data['to'], $tz)->startOfDay()->addDay();
        abort_if($start->diffInDays($end) > self::MAX_DAYS, 422, 'The export can cover at most '.self::MAX_DAYS.' days.');

        $filename = "orders-{$data['from']}-to-{$data['to']}.csv";

        return response()->streamDownload(function () use ($start, $end, $tz) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Khmer and other non-Latin text correctly
            fputcsv($out, self::COLUMNS);

            Order::query()
                ->with(['customer', 'coupon', 'payments'])
                ->withSum('items as copies', 'quantity')
                ->whereBetween('placed_at', [$start->utc(), $end->utc()->subSecond()])
                ->orderBy('placed_at')->orderBy('id')
                ->chunk(500, function ($orders) use ($out, $tz) {
                    foreach ($orders as $order) {
                        fputcsv($out, array_map([self::class, 'cell'], [
                            $order->order_number,
                            $order->placed_at->setTimezone($tz)->format('Y-m-d H:i'),
                            $order->status->value,
                            $order->customer?->name,
                            $order->customer?->email,
                            (int) $order->copies,
                            $order->subtotal,
                            $order->discount_amount,
                            $order->shipping_fee,
                            $order->tax_amount,
                            $order->total_amount,
                            $order->coupon?->code,
                            $order->payment_method->value,
                            $order->payments->sortByDesc('id')->first()?->status->value,
                            $order->shipping_city,
                            $order->shipping_country,
                        ]));
                    }
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Stops spreadsheet apps from running a cell as a formula (CSV injection). */
    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value)
            ? "'".$value
            : $value;
    }
}

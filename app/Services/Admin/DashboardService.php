<?php

namespace App\Services\Admin;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sales numbers on a cash basis: an order counts as revenue on the day it was delivered (cash on
 * delivery is collected then), and a refund counts on the day it was paid back.
 */
class DashboardService
{
    public const PERIODS = ['today', '7d', '30d', 'custom'];

    private const MAX_DAYS = 366;

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} [start, end) in the shop's timezone */
    public function period(string $period, ?string $from = null, ?string $to = null): array
    {
        $tz = config('shop.timezone');
        $today = CarbonImmutable::now($tz)->startOfDay();

        [$start, $end] = match ($period) {
            'today' => [$today, $today->addDay()],
            '7d' => [$today->subDays(6), $today->addDay()],
            '30d' => [$today->subDays(29), $today->addDay()],
            'custom' => [CarbonImmutable::parse($from, $tz)->startOfDay(), CarbonImmutable::parse($to, $tz)->startOfDay()->addDay()],
        };

        if ($start->diffInDays($end) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'The period can be at most '.self::MAX_DAYS.' days.']);
        }

        return [$start, $end];
    }

    public function summary(CarbonImmutable $start, CarbonImmutable $end): array
    {
        [$from, $to] = $this->utc($start, $end);

        $delivered = DB::table('orders')
            ->join('order_status_history as h', fn ($j) => $j->on('h.order_id', '=', 'orders.id')->where('h.status', OrderStatus::Delivered->value))
            ->whereNull('orders.deleted_at')
            ->whereBetween('h.created_at', [$from, $to->subSecond()])
            ->selectRaw('COUNT(DISTINCT orders.id) AS n, COALESCE(SUM(orders.total_amount), 0) AS total')
            ->first();

        $gross = Money::toCents($delivered->total);
        $refunds = Money::toCents(OrderReturn::where('status', ReturnStatus::Refunded)->whereBetween('resolved_at', [$from, $to->subSecond()])->sum('refund_amount'));
        $deliveredCount = (int) $delivered->n;

        $placed = Order::whereBetween('placed_at', [$from, $to->subSecond()]);

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->subDay()->toDateString(), 'timezone' => $start->timezoneName],
            'revenue' => [
                'gross_usd' => Money::format($gross),
                'refunds_usd' => Money::format($refunds),
                'net_usd' => Money::format($gross - $refunds),
            ],
            'delivered_orders' => $deliveredCount,
            'average_order_value_usd' => Money::format($deliveredCount > 0 ? intdiv($gross, $deliveredCount) : 0),
            'orders_placed' => (clone $placed)->count(),
            'orders_by_status' => collect(OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => 0])
                ->merge((clone $placed)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)),
            // Unfinished sign-ups aren't customers yet.
            'new_customers' => Customer::whereNotNull('email_verified_at')->whereBetween('created_at', [$from, $to->subSecond()])->count(),
            'best_sellers' => $this->bestSellers($from, $to),
            'open_returns' => OrderReturn::whereIn('status', [ReturnStatus::Requested, ReturnStatus::Approved])->count(),
            'low_stock' => $this->lowStock(),
        ];
    }

    /** One row per day of the period, including days without sales. */
    public function dailySales(CarbonImmutable $start, CarbonImmutable $end): array
    {
        [$from, $to] = $this->utc($start, $end);
        $tz = $start->timezoneName;
        $localDay = fn (string $column) => "DATE(({$column} AT TIME ZONE 'UTC') AT TIME ZONE ?)";

        $placed = DB::table('orders')->whereNull('deleted_at')->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('placed_at', [$from, $to->subSecond()])
            ->selectRaw($localDay('placed_at').' AS day, COUNT(*) AS n', [$tz])->groupBy('day')->pluck('n', 'day');

        $gross = DB::table('orders')
            ->join('order_status_history as h', fn ($j) => $j->on('h.order_id', '=', 'orders.id')->where('h.status', OrderStatus::Delivered->value))
            ->whereNull('orders.deleted_at')->whereBetween('h.created_at', [$from, $to->subSecond()])
            ->selectRaw($localDay('h.created_at').' AS day, SUM(orders.total_amount) AS total', [$tz])->groupBy('day')->pluck('total', 'day');

        $refunds = DB::table('returns')->where('status', ReturnStatus::Refunded->value)
            ->whereBetween('resolved_at', [$from, $to->subSecond()])
            ->selectRaw($localDay('resolved_at').' AS day, SUM(refund_amount) AS total', [$tz])->groupBy('day')->pluck('total', 'day');

        $rows = [];
        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $key = $day->toDateString();
            $g = Money::toCents($gross[$key] ?? 0);
            $r = Money::toCents($refunds[$key] ?? 0);
            $rows[] = [
                'date' => $key,
                'orders_placed' => (int) ($placed[$key] ?? 0),
                'gross_revenue_usd' => Money::format($g),
                'refunds_usd' => Money::format($r),
                'net_revenue_usd' => Money::format($g - $r),
            ];
        }

        return $rows;
    }

    /** Top 10 books by copies in orders placed in the period (cancelled orders excluded). */
    private function bestSellers(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('book_variants', 'book_variants.id', '=', 'order_items.book_variant_id')
            ->join('books', 'books.id', '=', 'book_variants.book_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.placed_at', [$from, $to->subSecond()])
            ->groupBy('books.id', 'books.title')
            ->selectRaw('books.id AS book_id, books.title, SUM(order_items.quantity) AS copies, SUM(order_items.subtotal) AS sales')
            ->orderByDesc('copies')->orderBy('books.id')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'book_id' => $row->book_id,
                'title' => $row->title,
                'copies_sold' => (int) $row->copies,
                'sales_usd' => Money::format(Money::toCents($row->sales)),
            ])
            ->all();
    }

    /** Active physical formats at or below their low-stock threshold, lowest stock first. */
    private function lowStock(): array
    {
        return BookVariant::query()
            ->with('book')
            ->where('is_active', true)
            ->whereIn('format', [BookFormat::Hardcover->value, BookFormat::Paperback->value])
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->whereHas('book')
            ->orderBy('stock_quantity')->orderBy('id')
            ->limit(20)
            ->get()
            ->map(fn ($v) => [
                'book_variant_id' => $v->id,
                'book_id' => $v->book_id,
                'title' => $v->book->title,
                'format' => $v->format->value,
                'sku' => $v->sku,
                'stock_quantity' => $v->stock_quantity,
                'low_stock_threshold' => $v->low_stock_threshold,
            ])
            ->all();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} the period converted to UTC for queries */
    private function utc(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [$start->utc(), $end->utc()];
    }
}

<?php

namespace App\Services\Admin;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Services\CurrencyService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
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

    public function __construct(private readonly CurrencyService $currency) {}

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

    /**
     * The period's numbers, plus (unless $compare is false) the same numbers for the period of equal length just
     * before it and the change in percent. USD amounts have a KHR twin at the current rate (null without a rate).
     */
    public function summary(CarbonImmutable $start, CarbonImmutable $end, bool $compare = true): array
    {
        $cacheKey = "dashboard:summary:{$start->toDateString()}:{$end->toDateString()}:".($compare ? '1' : '0');

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($start, $end, $compare) {
            [$from, $to] = $this->utc($start, $end);
            $now = $this->totals($start, $end);
            $placed = Order::whereBetween('placed_at', [$from, $to->subSecond()]);

            $summary = [
                'period' => ['from' => $start->toDateString(), 'to' => $end->subDay()->toDateString(), 'timezone' => $start->timezoneName],
                'revenue' => $this->revenue($now),
                'delivered_orders' => $now['delivered_orders'],
                'average_order_value_usd' => Money::format($now['average_order_value']),
                'average_order_value_khr' => $this->khr($now['average_order_value']),
                'orders_placed' => $now['orders_placed'],
                'orders_by_status' => collect(OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => 0])
                    ->merge((clone $placed)->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n)),
                'new_customers' => $now['new_customers'],
                'best_sellers' => $this->bestSellers($from, $to),
                'top_categories' => $this->topCategories($from, $to),
                'open_returns' => OrderReturn::whereIn('status', [ReturnStatus::Requested, ReturnStatus::Approved])->count(),
                'low_stock' => $this->lowStock(),
            ];

            if ($compare) {
                $days = (int) $start->diffInDays($end);
                [$prevStart, $prevEnd] = [$start->subDays($days), $start];
                $before = $this->totals($prevStart, $prevEnd);

                $summary['comparison'] = [
                    'period' => ['from' => $prevStart->toDateString(), 'to' => $prevEnd->subDay()->toDateString(), 'timezone' => $start->timezoneName],
                    'revenue' => $this->revenue($before),
                    'delivered_orders' => $before['delivered_orders'],
                    'average_order_value_usd' => Money::format($before['average_order_value']),
                    'average_order_value_khr' => $this->khr($before['average_order_value']),
                    'orders_placed' => $before['orders_placed'],
                    'new_customers' => $before['new_customers'],
                    'change_percent' => [
                        'gross_revenue' => $this->change($now['gross'], $before['gross']),
                        'refunds' => $this->change($now['refunds'], $before['refunds']),
                        'net_revenue' => $this->change($now['gross'] - $now['refunds'], $before['gross'] - $before['refunds']),
                        'delivered_orders' => $this->change($now['delivered_orders'], $before['delivered_orders']),
                        'average_order_value' => $this->change($now['average_order_value'], $before['average_order_value']),
                        'orders_placed' => $this->change($now['orders_placed'], $before['orders_placed']),
                        'new_customers' => $this->change($now['new_customers'], $before['new_customers']),
                    ],
                ];
            }

            return $summary;
        });
    }

    /**
     * The comparable numbers of one period. Cash basis: an order is revenue on the day it was delivered,
     * a refund counts on the day it was paid back. Money in cents.
     *
     * @return array{gross: int, refunds: int, delivered_orders: int, average_order_value: int, orders_placed: int, new_customers: int}
     */
    private function totals(CarbonImmutable $start, CarbonImmutable $end): array
    {
        [$from, $to] = $this->utc($start, $end);

        $delivered = DB::table('orders')
            ->join('order_status_history as h', fn ($j) => $j->on('h.order_id', '=', 'orders.id')->where('h.status', OrderStatus::Delivered->value))
            ->whereNull('orders.deleted_at')
            ->whereBetween('h.created_at', [$from, $to->subSecond()])
            ->selectRaw('COUNT(DISTINCT orders.id) AS n, COALESCE(SUM(orders.total_amount), 0) AS total')
            ->first();

        $gross = Money::toCents($delivered->total);
        $count = (int) $delivered->n;

        return [
            'gross' => $gross,
            'refunds' => Money::toCents(OrderReturn::where('status', ReturnStatus::Refunded)->whereBetween('resolved_at', [$from, $to->subSecond()])->sum('refund_amount')),
            'delivered_orders' => $count,
            'average_order_value' => $count > 0 ? intdiv($gross, $count) : 0,
            'orders_placed' => Order::whereBetween('placed_at', [$from, $to->subSecond()])->count(),
            // Unfinished sign-ups aren't customers yet.
            'new_customers' => Customer::verified()->whereBetween('created_at', [$from, $to->subSecond()])->count(),
        ];
    }

    /** @param array{gross: int, refunds: int} $totals */
    private function revenue(array $totals): array
    {
        $net = $totals['gross'] - $totals['refunds'];

        return [
            'gross_usd' => Money::format($totals['gross']),
            'refunds_usd' => Money::format($totals['refunds']),
            'net_usd' => Money::format($net),
            'gross_khr' => $this->khr($totals['gross']),
            'refunds_khr' => $this->khr($totals['refunds']),
            'net_khr' => $this->khr($net),
        ];
    }

    /**
     * Change from the previous period in percent, 2 decimals. Previous 0: 100.0 when there is something now,
     * else 0.0 (documented in docs/API.md; the frontend shows "new" instead of +100% in that case).
     */
    private function change(int $now, int $before): float
    {
        if ($before === 0) {
            return $now > 0 ? 100.0 : 0.0;
        }

        return round(($now - $before) / abs($before) * 100, 2);
    }

    private function khr(int $cents): ?string
    {
        return $this->currency->usdToKhr(Money::format($cents));
    }

    /** One row per day of the period, including days without sales. */
    public function dailySales(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cacheKey = "dashboard:sales:{$start->toDateString()}:{$end->toDateString()}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($start, $end) {
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
                    'net_revenue_khr' => $this->khr($g - $r),
                ];
            }

            return $rows;
        });
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
                'sales_khr' => $this->khr(Money::toCents($row->sales)),
            ])
            ->all();
    }

    /**
     * Top 5 categories by copies in orders placed in the period (cancelled orders excluded). A book in two
     * categories counts in both, so these rows can add up to more than the copies sold.
     */
    private function topCategories(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('book_variants', 'book_variants.id', '=', 'order_items.book_variant_id')
            ->join('book_categories', 'book_categories.book_id', '=', 'book_variants.book_id')
            ->join('categories', 'categories.id', '=', 'book_categories.category_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.placed_at', [$from, $to->subSecond()])
            ->groupBy('categories.id', 'categories.name')
            ->selectRaw('categories.id AS category_id, categories.name, SUM(order_items.quantity) AS copies, SUM(order_items.subtotal) AS sales')
            ->orderByDesc('copies')->orderBy('categories.id')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'category_id' => $row->category_id,
                'name' => $row->name,
                'copies_sold' => (int) $row->copies,
                'sales_usd' => Money::format(Money::toCents($row->sales)),
                'sales_khr' => $this->khr(Money::toCents($row->sales)),
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

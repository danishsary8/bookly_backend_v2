<?php

namespace App\Services\Admin;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReturnStatus;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Services\CurrencyService;
use App\Services\Orders\DeliveryArea;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportsService
{
    public const MAX_DAYS = 366;

    public function __construct(
        private readonly CurrencyService $currency,
    ) {}

    /**
     * Parse date range [start, end) in shop timezone and validate max 366 days.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function range(string $from, string $to): array
    {
        $tz = config('shop.timezone');
        $start = CarbonImmutable::parse($from, $tz)->startOfDay();
        $end = CarbonImmutable::parse($to, $tz)->startOfDay()->addDay();

        if ($start->gt($end)) {
            throw ValidationException::withMessages(['to' => 'The end date must be after or equal to the start date.']);
        }

        if ($start->diffInDays($end) > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => 'The report range can cover at most '.self::MAX_DAYS.' days.']);
        }

        return [$start, $end];
    }

    /**
     * Sales report: totals and series grouped by day, week, or month.
     */
    public function sales(CarbonImmutable $start, CarbonImmutable $end, string $group = 'day'): array
    {
        $cacheKey = "report:sales:{$start->toDateString()}:{$end->toDateString()}:{$group}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($start, $end, $group) {
            [$from, $to] = $this->utc($start, $end);
            $now = $this->salesTotals($from, $to);

            $days = (int) $start->diffInDays($end);
            [$prevStart, $prevEnd] = [$start->subDays($days), $start];
            [$prevFrom, $prevTo] = $this->utc($prevStart, $prevEnd);
            $before = $this->salesTotals($prevFrom, $prevTo);

            return [
                'period' => [
                    'from' => $start->toDateString(),
                    'to' => $end->subDay()->toDateString(),
                    'group' => $group,
                    'timezone' => $start->timezoneName,
                ],
                'totals' => $this->formatSalesTotals($now),
                'comparison' => [
                    'period' => [
                        'from' => $prevStart->toDateString(),
                        'to' => $prevEnd->subDay()->toDateString(),
                        'timezone' => $start->timezoneName,
                    ],
                    'totals' => $this->formatSalesTotals($before),
                    'change_percent' => [
                        'gross_revenue' => $this->change($now['gross'], $before['gross']),
                        'refunds' => $this->change($now['refunds'], $before['refunds']),
                        'net_revenue' => $this->change($now['gross'] - $now['refunds'], $before['gross'] - $before['refunds']),
                        'orders_count' => $this->change($now['orders_count'], $before['orders_count']),
                        'orders_placed' => $this->change($now['orders_placed'], $before['orders_placed']),
                        'average_order_value' => $this->change($now['average_order_value'], $before['average_order_value']),
                        'items_sold' => $this->change($now['items_sold'], $before['items_sold']),
                        'discounts' => $this->change($now['discounts'], $before['discounts']),
                        'shipping' => $this->change($now['shipping'], $before['shipping']),
                    ],
                ],
                'series' => $this->salesSeries($start, $end, $group),
            ];
        });
    }

    /**
     * Orders report: breakdown by status, delivery area, payment method, fulfillment speed, and cancellations.
     */
    public function orders(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cacheKey = "report:orders:{$start->toDateString()}:{$end->toDateString()}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($start, $end) {
            [$from, $to] = $this->utc($start, $end);
            $now = $this->ordersTotals($from, $to);

            $days = (int) $start->diffInDays($end);
            [$prevStart, $prevEnd] = [$start->subDays($days), $start];
            [$prevFrom, $prevTo] = $this->utc($prevStart, $prevEnd);
            $before = $this->ordersTotals($prevFrom, $prevTo);

            return [
                'period' => [
                    'from' => $start->toDateString(),
                    'to' => $end->subDay()->toDateString(),
                    'timezone' => $start->timezoneName,
                ],
                'total_orders' => $now['total_orders'],
                'orders_by_status' => $now['orders_by_status'],
                'cancellation_rate' => $now['cancellation_rate'],
                'average_delivery_hours' => $now['average_delivery_hours'],
                'orders_by_payment_method' => $now['orders_by_payment_method'],
                'orders_by_delivery_area' => $now['orders_by_delivery_area'],
                'comparison' => [
                    'period' => [
                        'from' => $prevStart->toDateString(),
                        'to' => $prevEnd->subDay()->toDateString(),
                        'timezone' => $start->timezoneName,
                    ],
                    'total_orders' => $before['total_orders'],
                    'cancellation_rate' => $before['cancellation_rate'],
                    'average_delivery_hours' => $before['average_delivery_hours'],
                    'change_percent' => [
                        'total_orders' => $this->change($now['total_orders'], $before['total_orders']),
                        'cancellation_rate' => $this->change((int) round($now['cancellation_rate'] * 100), (int) round($before['cancellation_rate'] * 100)),
                        'average_delivery_hours' => $this->change((int) round(($now['average_delivery_hours'] ?? 0) * 100), (int) round(($before['average_delivery_hours'] ?? 0) * 100)),
                    ],
                ],
            ];
        });
    }

    /**
     * Products report: best sellers, worst sellers with stock, low-stock, and revenue by category.
     */
    public function products(CarbonImmutable $start, CarbonImmutable $end, int $limit = 10): array
    {
        $cacheKey = "report:products:{$start->toDateString()}:{$end->toDateString()}:{$limit}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($start, $end, $limit) {
            [$from, $to] = $this->utc($start, $end);

            return [
                'period' => [
                    'from' => $start->toDateString(),
                    'to' => $end->subDay()->toDateString(),
                    'timezone' => $start->timezoneName,
                ],
                'best_sellers_by_units' => $this->bestSellersByUnits($from, $to, $limit),
                'best_sellers_by_revenue' => $this->bestSellersByRevenue($from, $to, $limit),
                'worst_sellers' => $this->worstSellers($from, $to, $limit),
                'low_stock' => $this->lowStock($limit),
                'revenue_by_category' => $this->revenueByCategory($from, $to),
            ];
        });
    }

    /**
     * Customers report: verified customer acquisition series, returning customer rate, and top spenders.
     */
    public function customers(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cacheKey = "report:customers:{$start->toDateString()}:{$end->toDateString()}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($start, $end) {
            [$from, $to] = $this->utc($start, $end);
            $now = $this->customerStats($from, $to);

            $days = (int) $start->diffInDays($end);
            [$prevStart, $prevEnd] = [$start->subDays($days), $start];
            [$prevFrom, $prevTo] = $this->utc($prevStart, $prevEnd);
            $before = $this->customerStats($prevFrom, $prevTo);

            return [
                'period' => [
                    'from' => $start->toDateString(),
                    'to' => $end->subDay()->toDateString(),
                    'timezone' => $start->timezoneName,
                ],
                'total_new_customers' => $now['total_new_customers'],
                'returning_customer_rate' => $now['returning_customer_rate'],
                'new_customers_series' => $this->customerSeries($start, $end),
                'top_customers' => $this->topCustomers($from, $to, 10),
                'comparison' => [
                    'period' => [
                        'from' => $prevStart->toDateString(),
                        'to' => $prevEnd->subDay()->toDateString(),
                        'timezone' => $start->timezoneName,
                    ],
                    'total_new_customers' => $before['total_new_customers'],
                    'returning_customer_rate' => $before['returning_customer_rate'],
                    'change_percent' => [
                        'total_new_customers' => $this->change($now['total_new_customers'], $before['total_new_customers']),
                        'returning_customer_rate' => $this->change((int) round($now['returning_customer_rate'] * 100), (int) round($before['returning_customer_rate'] * 100)),
                    ],
                ],
            ];
        });
    }

    /**
     * Conversion funnel: Carts with items -> Orders placed -> Orders delivered -> Orders returned.
     */
    public function funnel(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cacheKey = "report:funnel:{$start->toDateString()}:{$end->toDateString()}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($start, $end) {
            [$from, $to] = $this->utc($start, $end);

            // Active carts with items created or updated in range
            $activeCartCount = DB::table('cart_items')
                ->whereBetween('created_at', [$from, $to->subSecond()])
                ->distinct('cart_id')
                ->count('cart_id');

            // Orders placed in range
            $ordersPlaced = DB::table('orders')
                ->whereNull('deleted_at')
                ->whereBetween('placed_at', [$from, $to->subSecond()])
                ->count();

            // Total carts that had items = currently active carts created/updated in range + orders placed in range
            $cartsWithItems = $activeCartCount + $ordersPlaced;

            // Orders placed in range that reached delivered
            $ordersDelivered = DB::table('orders')
                ->join('order_status_history as h', fn ($j) => $j->on('h.order_id', '=', 'orders.id')->where('h.status', OrderStatus::Delivered->value))
                ->whereNull('orders.deleted_at')
                ->whereBetween('orders.placed_at', [$from, $to->subSecond()])
                ->count(DB::raw('DISTINCT orders.id'));

            // Orders placed in range that had returns
            $ordersReturned = DB::table('returns')
                ->join('orders', 'orders.id', '=', 'returns.order_id')
                ->whereNull('orders.deleted_at')
                ->whereBetween('orders.placed_at', [$from, $to->subSecond()])
                ->whereIn('returns.status', [ReturnStatus::Approved->value, ReturnStatus::Refunded->value])
                ->count(DB::raw('DISTINCT orders.id'));

            $steps = [
                [
                    'key' => 'carts_with_items',
                    'name' => 'Carts with items',
                    'count' => $cartsWithItems,
                ],
                [
                    'key' => 'orders_placed',
                    'name' => 'Orders placed',
                    'count' => $ordersPlaced,
                ],
                [
                    'key' => 'orders_delivered',
                    'name' => 'Orders delivered',
                    'count' => $ordersDelivered,
                ],
                [
                    'key' => 'orders_returned',
                    'name' => 'Orders returned',
                    'count' => $ordersReturned,
                ],
            ];

            $firstCount = $cartsWithItems;
            $previousCount = $cartsWithItems;

            foreach ($steps as $i => &$step) {
                $count = $step['count'];
                $step['conversion_rate'] = $firstCount > 0 ? round(($count / $firstCount) * 100, 2) : 0.0;
                $step['step_conversion_rate'] = $previousCount > 0 ? round(($count / $previousCount) * 100, 2) : 0.0;
                $step['dropoff_rate'] = $previousCount > 0 ? round(max(0, ($previousCount - $count) / $previousCount * 100), 2) : 0.0;
                $previousCount = $count;
            }

            return [
                'period' => [
                    'from' => $start->toDateString(),
                    'to' => $end->subDay()->toDateString(),
                    'timezone' => $start->timezoneName,
                ],
                'steps' => $steps,
            ];
        });
    }

    /**
     * Coupons and refunds report.
     */
    public function coupons(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cacheKey = "report:coupons:{$start->toDateString()}:{$end->toDateString()}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($start, $end) {
            [$from, $to] = $this->utc($start, $end);

            $coupons = DB::table('orders')
                ->join('coupons', 'coupons.id', '=', 'orders.coupon_id')
                ->whereNull('orders.deleted_at')
                ->where('orders.status', '!=', OrderStatus::Cancelled->value)
                ->whereBetween('orders.placed_at', [$from, $to->subSecond()])
                ->groupBy('coupons.id', 'coupons.code', 'coupons.type', 'coupons.value')
                ->selectRaw('coupons.id, coupons.code, coupons.type, coupons.value, COUNT(orders.id) as times_used, SUM(orders.discount_amount) as total_discount, SUM(orders.total_amount) as total_revenue')
                ->orderByDesc('times_used')
                ->get()
                ->map(fn ($r) => [
                    'coupon_id' => $r->id,
                    'code' => $r->code,
                    'type' => $r->type,
                    'value' => (string) $r->value,
                    'times_used' => (int) $r->times_used,
                    'discount_usd' => Money::format(Money::toCents($r->total_discount)),
                    'discount_khr' => $this->khr(Money::toCents($r->total_discount)),
                    'revenue_usd' => Money::format(Money::toCents($r->total_revenue)),
                    'revenue_khr' => $this->khr(Money::toCents($r->total_revenue)),
                ])
                ->all();

            $refundsQuery = DB::table('returns')
                ->where('status', ReturnStatus::Refunded->value)
                ->whereBetween('resolved_at', [$from, $to->subSecond()]);

            $totalRefundsCount = (clone $refundsQuery)->count();
            $totalRefundsAmountCents = Money::toCents((clone $refundsQuery)->sum('refund_amount'));

            $byReason = (clone $refundsQuery)
                ->groupBy('reason')
                ->selectRaw('reason, COUNT(*) as count, SUM(refund_amount) as total')
                ->orderByDesc('count')
                ->get()
                ->map(fn ($r) => [
                    'reason' => $r->reason,
                    'count' => (int) $r->count,
                    'amount_usd' => Money::format(Money::toCents($r->total)),
                    'amount_khr' => $this->khr(Money::toCents($r->total)),
                ])
                ->all();

            return [
                'period' => [
                    'from' => $start->toDateString(),
                    'to' => $end->subDay()->toDateString(),
                    'timezone' => $start->timezoneName,
                ],
                'coupons' => $coupons,
                'refunds' => [
                    'count' => $totalRefundsCount,
                    'amount_usd' => Money::format($totalRefundsAmountCents),
                    'amount_khr' => $this->khr($totalRefundsAmountCents),
                    'by_reason' => $byReason,
                ],
            ];
        });
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    private function salesTotals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $delivered = DB::table('orders')
            ->join('order_status_history as h', fn ($j) => $j->on('h.order_id', '=', 'orders.id')->where('h.status', OrderStatus::Delivered->value))
            ->whereNull('orders.deleted_at')
            ->whereBetween('h.created_at', [$from, $to->subSecond()])
            ->selectRaw('COUNT(DISTINCT orders.id) AS n, COALESCE(SUM(orders.total_amount), 0) AS total, COALESCE(SUM(orders.discount_amount), 0) AS discounts, COALESCE(SUM(orders.shipping_fee), 0) AS shipping')
            ->first();

        $gross = Money::toCents($delivered->total);
        $count = (int) $delivered->n;

        $itemsSold = (int) DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('order_status_history as h', fn ($j) => $j->on('h.order_id', '=', 'orders.id')->where('h.status', OrderStatus::Delivered->value))
            ->whereNull('orders.deleted_at')
            ->whereBetween('h.created_at', [$from, $to->subSecond()])
            ->sum('order_items.quantity');

        $refunds = Money::toCents(OrderReturn::where('status', ReturnStatus::Refunded)
            ->whereBetween('resolved_at', [$from, $to->subSecond()])
            ->sum('refund_amount'));

        $ordersPlaced = Order::whereBetween('placed_at', [$from, $to->subSecond()])->count();

        return [
            'gross' => $gross,
            'refunds' => $refunds,
            'discounts' => Money::toCents($delivered->discounts),
            'shipping' => Money::toCents($delivered->shipping),
            'orders_count' => $count,
            'items_sold' => $itemsSold,
            'average_order_value' => $count > 0 ? intdiv($gross, $count) : 0,
            'orders_placed' => $ordersPlaced,
        ];
    }

    private function formatSalesTotals(array $totals): array
    {
        $net = $totals['gross'] - $totals['refunds'];

        return [
            'gross_revenue_usd' => Money::format($totals['gross']),
            'gross_revenue_khr' => $this->khr($totals['gross']),
            'refunds_usd' => Money::format($totals['refunds']),
            'refunds_khr' => $this->khr($totals['refunds']),
            'net_revenue_usd' => Money::format($net),
            'net_revenue_khr' => $this->khr($net),
            'orders_count' => $totals['orders_count'],
            'orders_placed' => $totals['orders_placed'],
            'items_sold' => $totals['items_sold'],
            'average_order_value_usd' => Money::format($totals['average_order_value']),
            'average_order_value_khr' => $this->khr($totals['average_order_value']),
            'discount_usd' => Money::format($totals['discounts']),
            'discount_khr' => $this->khr($totals['discounts']),
            'shipping_usd' => Money::format($totals['shipping']),
            'shipping_khr' => $this->khr($totals['shipping']),
        ];
    }

    private function salesSeries(CarbonImmutable $start, CarbonImmutable $end, string $group): array
    {
        [$from, $to] = $this->utc($start, $end);
        $tz = $start->timezoneName;

        $truncUnit = match ($group) {
            'week' => 'week',
            'month' => 'month',
            default => 'day',
        };

        $localTrunc = fn (string $column) => "TO_CHAR(DATE_TRUNC('{$truncUnit}', ({$column} AT TIME ZONE 'UTC') AT TIME ZONE ?), 'YYYY-MM-DD')";

        $deliveredRows = DB::table('orders')
            ->join('order_status_history as h', fn ($j) => $j->on('h.order_id', '=', 'orders.id')->where('h.status', OrderStatus::Delivered->value))
            ->whereNull('orders.deleted_at')
            ->whereBetween('h.created_at', [$from, $to->subSecond()])
            ->selectRaw($localTrunc('h.created_at').' AS bucket, COUNT(DISTINCT orders.id) AS orders_count, COALESCE(SUM(orders.total_amount), 0) AS total', [$tz])
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $deliveredCounts = DB::table('orders')
            ->join('order_status_history as h', fn ($j) => $j->on('h.order_id', '=', 'orders.id')->where('h.status', OrderStatus::Delivered->value))
            ->whereNull('orders.deleted_at')
            ->whereBetween('h.created_at', [$from, $to->subSecond()])
            ->selectRaw($localTrunc('h.created_at').' AS bucket, COUNT(DISTINCT orders.id) AS orders_count', [$tz])
            ->groupBy('bucket')
            ->pluck('orders_count', 'bucket');

        $itemsRows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('order_status_history as h', fn ($j) => $j->on('h.order_id', '=', 'orders.id')->where('h.status', OrderStatus::Delivered->value))
            ->whereNull('orders.deleted_at')
            ->whereBetween('h.created_at', [$from, $to->subSecond()])
            ->selectRaw($localTrunc('h.created_at').' AS bucket, COALESCE(SUM(order_items.quantity), 0) AS items', [$tz])
            ->groupBy('bucket')
            ->pluck('items', 'bucket');

        $refundRows = DB::table('returns')
            ->where('status', ReturnStatus::Refunded->value)
            ->whereBetween('resolved_at', [$from, $to->subSecond()])
            ->selectRaw($localTrunc('resolved_at').' AS bucket, COALESCE(SUM(refund_amount), 0) AS total', [$tz])
            ->groupBy('bucket')
            ->pluck('total', 'bucket');

        $series = [];
        // Step from the start of the first bucket: adding a month to 31 January would skip February.
        $cursor = match ($group) {
            'week' => $start->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $start->startOfMonth(),
            default => $start,
        };

        while ($cursor->lt($end)) {
            $key = match ($group) {
                'week' => $cursor->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
                'month' => $cursor->startOfMonth()->toDateString(),
                default => $cursor->toDateString(),
            };

            // Avoid duplicate keys when stepping
            if (! isset($series[$key])) {
                $g = Money::toCents($deliveredRows[$key] ?? 0);
                $r = Money::toCents($refundRows[$key] ?? 0);
                $ordersCount = (int) ($deliveredCounts[$key] ?? 0);
                $items = (int) ($itemsRows[$key] ?? 0);
                $net = $g - $r;

                $series[$key] = [
                    'date' => $key,
                    'orders_count' => $ordersCount,
                    'gross_revenue_usd' => Money::format($g),
                    'gross_revenue_khr' => $this->khr($g),
                    'refunds_usd' => Money::format($r),
                    'refunds_khr' => $this->khr($r),
                    'net_revenue_usd' => Money::format($net),
                    'net_revenue_khr' => $this->khr($net),
                    'items_sold' => $items,
                    'average_order_value_usd' => Money::format($ordersCount > 0 ? intdiv($g, $ordersCount) : 0),
                    'average_order_value_khr' => $this->khr($ordersCount > 0 ? intdiv($g, $ordersCount) : 0),
                ];
            }

            $cursor = match ($group) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return array_values($series);
    }

    private function ordersTotals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $placedQuery = Order::query()->whereNull('deleted_at')->whereBetween('placed_at', [$from, $to->subSecond()]);
        $totalOrders = (clone $placedQuery)->count();

        $statusCounts = (clone $placedQuery)
            ->selectRaw('status, COUNT(*) AS n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $allStatuses = collect(OrderStatus::cases())
            ->mapWithKeys(fn ($s) => [$s->value => 0])
            ->merge($statusCounts->map(fn ($n) => (int) $n))
            ->all();

        $cancelledCount = $allStatuses[OrderStatus::Cancelled->value] ?? 0;
        $cancellationRate = $totalOrders > 0 ? round(($cancelledCount / $totalOrders) * 100, 2) : 0.0;

        // Average delivery hours from placed_at to delivered history timestamp for delivered orders
        $avgDelivery = DB::table('orders')
            ->join('order_status_history as h', fn ($j) => $j->on('h.order_id', '=', 'orders.id')->where('h.status', OrderStatus::Delivered->value))
            ->whereNull('orders.deleted_at')
            ->where('orders.status', OrderStatus::Delivered->value)
            ->whereBetween('orders.placed_at', [$from, $to->subSecond()])
            ->selectRaw('AVG(EXTRACT(EPOCH FROM (h.created_at - orders.placed_at)) / 3600) as avg_hours')
            ->value('avg_hours');

        $paymentMethods = (clone $placedQuery)
            ->selectRaw('payment_method, COUNT(*) AS n')
            ->groupBy('payment_method')
            ->pluck('n', 'payment_method');

        $allPaymentMethods = collect(PaymentMethod::cases())
            ->mapWithKeys(fn ($p) => [$p->value => 0])
            ->merge($paymentMethods->map(fn ($n) => (int) $n))
            ->all();

        // Delivery area: the checkout rule (DeliveryArea), applied once per distinct city/province pair.
        $areaCounts = [DeliveryArea::PHNOM_PENH => 0, DeliveryArea::PROVINCES => 0];
        (clone $placedQuery)->toBase()
            ->selectRaw('shipping_city, shipping_state, COUNT(*) AS n')
            ->groupBy('shipping_city', 'shipping_state')
            ->get()
            ->each(function ($row) use (&$areaCounts) {
                $areaCounts[DeliveryArea::forPlace($row->shipping_city, $row->shipping_state)] += (int) $row->n;
            });

        return [
            'total_orders' => $totalOrders,
            'orders_by_status' => $allStatuses,
            'cancellation_rate' => $cancellationRate,
            'average_delivery_hours' => $avgDelivery !== null ? round((float) $avgDelivery, 1) : null,
            'orders_by_payment_method' => $allPaymentMethods,
            'orders_by_delivery_area' => [
                'phnom_penh' => $areaCounts[DeliveryArea::PHNOM_PENH],
                'provinces' => $areaCounts[DeliveryArea::PROVINCES],
            ],
        ];
    }

    private function bestSellersByUnits(CarbonImmutable $from, CarbonImmutable $to, int $limit): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('book_variants', 'book_variants.id', '=', 'order_items.book_variant_id')
            ->join('books', 'books.id', '=', 'book_variants.book_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.placed_at', [$from, $to->subSecond()])
            ->groupBy('books.id', 'books.title', 'book_variants.id', 'book_variants.format')
            ->selectRaw('books.id AS book_id, books.title, book_variants.id AS variant_id, book_variants.format, SUM(order_items.quantity) AS units, SUM(order_items.subtotal) AS revenue')
            ->orderByDesc('units')->orderBy('books.id')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'book_id' => $r->book_id,
                'variant_id' => $r->variant_id,
                'title' => $r->title,
                'format' => $r->format,
                'units_sold' => (int) $r->units,
                'revenue_usd' => Money::format(Money::toCents($r->revenue)),
                'revenue_khr' => $this->khr(Money::toCents($r->revenue)),
            ])
            ->all();
    }

    private function bestSellersByRevenue(CarbonImmutable $from, CarbonImmutable $to, int $limit): array
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('book_variants', 'book_variants.id', '=', 'order_items.book_variant_id')
            ->join('books', 'books.id', '=', 'book_variants.book_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.placed_at', [$from, $to->subSecond()])
            ->groupBy('books.id', 'books.title', 'book_variants.id', 'book_variants.format')
            ->selectRaw('books.id AS book_id, books.title, book_variants.id AS variant_id, book_variants.format, SUM(order_items.quantity) AS units, SUM(order_items.subtotal) AS revenue')
            ->orderByDesc('revenue')->orderByDesc('units')->orderBy('books.id')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'book_id' => $r->book_id,
                'variant_id' => $r->variant_id,
                'title' => $r->title,
                'format' => $r->format,
                'units_sold' => (int) $r->units,
                'revenue_usd' => Money::format(Money::toCents($r->revenue)),
                'revenue_khr' => $this->khr(Money::toCents($r->revenue)),
            ])
            ->all();
    }

    private function worstSellers(CarbonImmutable $from, CarbonImmutable $to, int $limit): array
    {
        $salesSubquery = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.placed_at', [$from, $to->subSecond()])
            ->groupBy('order_items.book_variant_id')
            ->selectRaw('order_items.book_variant_id, SUM(order_items.quantity) AS units, SUM(order_items.subtotal) AS revenue');

        return DB::table('book_variants')
            ->join('books', 'books.id', '=', 'book_variants.book_id')
            ->leftJoinSub($salesSubquery, 'sales', 'sales.book_variant_id', '=', 'book_variants.id')
            ->where('book_variants.is_active', true)
            ->where('book_variants.stock_quantity', '>', 0)
            ->whereNull('books.deleted_at')
            ->selectRaw('books.id AS book_id, books.title, book_variants.id AS variant_id, book_variants.format, book_variants.stock_quantity, COALESCE(sales.units, 0) AS units_sold, COALESCE(sales.revenue, 0) AS revenue')
            ->orderBy('units_sold')->orderBy('revenue')->orderByDesc('book_variants.stock_quantity')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'book_id' => $r->book_id,
                'variant_id' => $r->variant_id,
                'title' => $r->title,
                'format' => $r->format,
                'stock_quantity' => (int) $r->stock_quantity,
                'units_sold' => (int) $r->units_sold,
                'revenue_usd' => Money::format(Money::toCents($r->revenue)),
            ])
            ->all();
    }

    private function lowStock(int $limit): array
    {
        return BookVariant::query()
            ->with('book')
            ->where('is_active', true)
            ->whereIn('format', [BookFormat::Hardcover->value, BookFormat::Paperback->value])
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->whereHas('book')
            ->orderBy('stock_quantity')->orderBy('id')
            ->limit($limit)
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

    private function revenueByCategory(CarbonImmutable $from, CarbonImmutable $to): array
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
            ->selectRaw('categories.id AS category_id, categories.name, SUM(order_items.quantity) AS units, SUM(order_items.subtotal) AS revenue')
            ->orderByDesc('revenue')->orderByDesc('units')->orderBy('categories.id')
            ->get()
            ->map(fn ($r) => [
                'category_id' => $r->category_id,
                'name' => $r->name,
                'units_sold' => (int) $r->units,
                'revenue_usd' => Money::format(Money::toCents($r->revenue)),
                'revenue_khr' => $this->khr(Money::toCents($r->revenue)),
            ])
            ->all();
    }

    private function customerStats(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $newCustomers = Customer::verified()->whereBetween('created_at', [$from, $to->subSecond()])->count();

        $ordersPerCustomer = DB::table('orders')
            ->whereNull('deleted_at')
            ->whereBetween('placed_at', [$from, $to->subSecond()])
            ->groupBy('customer_id')
            ->selectRaw('customer_id, COUNT(*) as order_count')
            ->get();

        $totalCustomersWithOrders = $ordersPerCustomer->count();
        $returningCustomers = $ordersPerCustomer->filter(fn ($r) => $r->order_count >= 2)->count();

        $returningRate = $totalCustomersWithOrders > 0
            ? round(($returningCustomers / $totalCustomersWithOrders) * 100, 2)
            : 0.0;

        return [
            'total_new_customers' => $newCustomers,
            'returning_customer_rate' => $returningRate,
        ];
    }

    private function customerSeries(CarbonImmutable $start, CarbonImmutable $end): array
    {
        [$from, $to] = $this->utc($start, $end);
        $tz = $start->timezoneName;
        $localDay = fn (string $col) => "TO_CHAR(DATE(({$col} AT TIME ZONE 'UTC') AT TIME ZONE ?), 'YYYY-MM-DD')";

        $rows = DB::table('customers')
            ->where(fn ($q) => $q->whereNotNull('email_verified_at')->orWhereNotNull('phone_verified_at'))
            ->whereBetween('created_at', [$from, $to->subSecond()])
            ->selectRaw($localDay('created_at').' AS day, COUNT(*) AS count', [$tz])
            ->groupBy('day')
            ->pluck('count', 'day');

        $series = [];
        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $key = $day->toDateString();
            $series[] = [
                'date' => $key,
                'new_customers' => (int) ($rows[$key] ?? 0),
            ];
        }

        return $series;
    }

    private function topCustomers(CarbonImmutable $from, CarbonImmutable $to, int $limit): array
    {
        return DB::table('orders')
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.placed_at', [$from, $to->subSecond()])
            ->groupBy('customers.id', 'customers.name', 'customers.email')
            ->selectRaw('customers.id AS customer_id, customers.name, customers.email, COUNT(orders.id) AS orders_count, SUM(orders.total_amount) AS total_spent')
            ->orderByDesc('total_spent')->orderBy('customers.id')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'customer_id' => $r->customer_id,
                'name' => $r->name,
                'email' => $r->email,
                'orders_count' => (int) $r->orders_count,
                'total_spent_usd' => Money::format(Money::toCents($r->total_spent)),
                'total_spent_khr' => $this->khr(Money::toCents($r->total_spent)),
            ])
            ->all();
    }

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

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function utc(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return [$start->utc(), $end->utc()];
    }
}

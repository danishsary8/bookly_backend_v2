<?php

namespace App\Services\Admin;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Models\BookVariant;
use App\Services\CurrencyService;
use App\Services\Orders\DeliveryArea;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The dashboard's extra numbers (docs/superpowers/specs/2026-11-01-bi-dashboard-design.md, branch 1): daily trends for
 * this period and the one before, dead stock, stock value, top authors, provinces, promotions, repeat customers, and
 * the menu badge counts. Every number uses the same rules as the dashboard summary; everything is cached as arrays.
 */
class DashboardInsightsService
{
    /** Owner 2026-11-01 (2A): in stock and no copy sold in this many days. */
    public const DEAD_STOCK_DAYS = 60;

    private const PRINTED = [BookFormat::Hardcover->value, BookFormat::Paperback->value];

    public function __construct(private readonly CurrencyService $currency) {}

    public function insights(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $cacheKey = "dashboard:insights:{$start->toDateString()}:{$end->toDateString()}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($start, $end) {
            $days = (int) $start->diffInDays($end);
            $prevStart = $start->subDays($days);

            return [
                'period' => ['from' => $start->toDateString(), 'to' => $end->subDay()->toDateString(), 'timezone' => $start->timezoneName],
                'trend' => $this->trend($start, $end, $prevStart),
                'dead_stock' => $this->deadStock(),
                'stock_value' => $this->stockValue(),
                'top_authors' => $this->topAuthors($start, $end),
                'provinces' => $this->provinces($start, $end),
                'promotions' => $this->promotions($start, $end),
                'repeat_customer_rate' => $this->repeatCustomerRate($start, $end),
                'order_ratings' => $this->orderRatings($start, $end),
                'cart_reminder_recovery' => $this->cartReminderRecovery($start, $end),
                'payment_methods' => $this->paymentMethods($start, $end),
                'attention' => $this->attention(),
                'activity' => $this->recentActivity(),
                'delivery_areas' => $this->deliveryAreas($start, $end),
            ];
        });
    }

    private function attention(): array
    {
        $counts = $this->counts();

        return [
            'pending_orders' => $counts['pending_orders'],
            'open_returns' => $counts['open_returns'],
            'low_stock' => $counts['low_stock'],
            'waiting_orders' => DB::table('orders')->whereNull('deleted_at')->where('is_waiting_stock', true)
                ->whereIn('status', [OrderStatus::Pending->value, OrderStatus::Paid->value, OrderStatus::Processing->value])->count(),
            'recent_reviews' => DB::table('reviews')->where('created_at', '>=', now()->subDays(7))->count(),
            'open_chats' => DB::table('support_conversations')->whereIn('status', ['open', 'pending'])->count(),
        ];
    }

    private function recentActivity(): array
    {
        return DB::table('admin_audit_logs as log')->leftJoin('staff_users as staff', 'staff.id', '=', 'log.staff_user_id')
            ->orderByDesc('log.created_at')->orderByDesc('log.id')->limit(10)
            ->get(['log.id', 'log.action', 'log.entity_type', 'log.entity_id', 'log.created_at', 'staff.name as staff_name'])
            ->map(fn ($row) => [
                'id' => $row->id, 'action' => $row->action, 'entity_type' => $row->entity_type,
                'entity_id' => $row->entity_id, 'staff_name' => $row->staff_name,
                'created_at' => CarbonImmutable::parse($row->created_at, 'UTC')->toIso8601ZuluString(),
            ])->all();
    }

    private function deliveryAreas(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $counts = ['phnom_penh' => 0, 'provinces' => 0, 'unknown' => 0];
        $rows = $this->placedOrders($start, $end)->groupBy('shipping_city', 'shipping_state')
            ->selectRaw('shipping_city, shipping_state, COUNT(*) AS orders')->get();
        foreach ($rows as $row) {
            $province = DeliveryArea::detectProvince($row->shipping_city, $row->shipping_state);
            $key = $province === null ? 'unknown' : ($province['code'] === 'phnom_penh' ? 'phnom_penh' : 'provinces');
            $counts[$key] += (int) $row->orders;
        }

        return $counts;
    }

    /** Menu badges for every staff member (owner 5A): small, cached for a minute. */
    public function counts(): array
    {
        return Cache::remember('dashboard:counts', 60, fn () => [
            'pending_orders' => DB::table('orders')->whereNull('deleted_at')->where('status', OrderStatus::Pending->value)->count(),
            'open_returns' => DB::table('returns')->whereIn('status', [ReturnStatus::Requested->value, ReturnStatus::Approved->value])->count(),
            'low_stock' => BookVariant::query()
                ->where('is_active', true)
                ->whereIn('format', self::PRINTED)
                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
                ->whereHas('book')
                ->count(),
        ]);
    }

    private function orderRatings(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $row = DB::table('order_ratings')->where('created_at', '>=', $start->utc())->where('created_at', '<', $end->utc())
            ->selectRaw('COUNT(*) AS count, AVG(stars) AS average')->first();

        return ['count' => (int) $row->count, 'average' => $row->average === null ? null : round((float) $row->average, 1)];
    }

    /**
     * One row per day for this period, and the same day index of the previous period (for the dashed line). Same rules
     * as the summary cards: net revenue = delivered order totals on the delivery day less refunds on the refund day;
     * orders placed (cancelled ones included, as the card counts them); average order over delivered orders; new
     * verified customers.
     */
    private function trend(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $prevStart): array
    {
        $now = $this->daily($start, $end);
        $before = $this->daily($prevStart, $start);

        $rows = [];
        foreach ($now as $i => $day) {
            $prev = $before[$i] ?? null;
            $rows[] = [
                'date' => $day['date'],
                'previous_date' => $prev['date'] ?? null,
                'net_revenue_usd' => Money::format($day['net']),
                'previous_net_revenue_usd' => Money::format($prev['net'] ?? 0),
                'orders_placed' => $day['orders'],
                'previous_orders_placed' => $prev['orders'] ?? 0,
                'average_order_usd' => Money::format($day['average']),
                'previous_average_order_usd' => Money::format($prev['average'] ?? 0),
                'new_customers' => $day['customers'],
                'previous_new_customers' => $prev['customers'] ?? 0,
                'books_sold' => $day['books'],
                'previous_books_sold' => $prev['books'] ?? 0,
                'ebooks_sold' => $day['ebooks'],
                'previous_ebooks_sold' => $prev['ebooks'] ?? 0,
            ];
        }

        return $rows;
    }

    /** @return list<array{date: string, net: int, orders: int, average: int, customers: int}> */
    private function daily(CarbonImmutable $start, CarbonImmutable $end): array
    {
        [$from, $to] = [$start->utc(), $end->utc()->subSecond()];
        $tz = $start->timezoneName;
        $day = fn (string $column) => "DATE(({$column} AT TIME ZONE 'UTC') AT TIME ZONE ?)";

        $delivered = DB::table('orders')
            ->join('order_status_history as h', fn ($j) => $j->on('h.order_id', '=', 'orders.id')->where('h.status', OrderStatus::Delivered->value))
            ->whereNull('orders.deleted_at')->whereBetween('h.created_at', [$from, $to])
            ->selectRaw($day('h.created_at').' AS day, COUNT(DISTINCT orders.id) AS n, SUM(orders.total_amount) AS total', [$tz])
            ->groupBy('day')->get()->keyBy('day');
        $refunds = DB::table('returns')->where('status', ReturnStatus::Refunded->value)->whereBetween('resolved_at', [$from, $to])
            ->selectRaw($day('resolved_at').' AS day, SUM(refund_amount) AS total', [$tz])->groupBy('day')->pluck('total', 'day');
        $placed = DB::table('orders')->whereNull('deleted_at')->whereBetween('placed_at', [$from, $to])
            ->selectRaw($day('placed_at').' AS day, COUNT(*) AS n', [$tz])->groupBy('day')->pluck('n', 'day');
        $customers = DB::table('customers')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNotNull('email_verified_at')->orWhereNotNull('phone_verified_at'))
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw($day('created_at').' AS day, COUNT(*) AS n', [$tz])->groupBy('day')->pluck('n', 'day');
        $copies = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->leftJoin('book_variants', 'book_variants.id', '=', 'order_items.book_variant_id')
            ->whereNull('orders.deleted_at')->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.placed_at', [$from, $to])
            ->selectRaw($day('orders.placed_at').' AS day, SUM(order_items.quantity) AS books, SUM(CASE WHEN COALESCE(order_items.format, book_variants.format) = ? THEN order_items.quantity ELSE 0 END) AS ebooks', [$tz, BookFormat::Ebook->value])
            ->groupBy('day')->get()->keyBy('day');

        $rows = [];
        for ($d = $start; $d->lt($end); $d = $d->addDay()) {
            $key = $d->toDateString();
            $gross = Money::toCents($delivered[$key]->total ?? 0);
            $count = (int) ($delivered[$key]->n ?? 0);
            $rows[] = [
                'date' => $key,
                'net' => $gross - Money::toCents($refunds[$key] ?? 0),
                'orders' => (int) ($placed[$key] ?? 0),
                'average' => $count > 0 ? intdiv($gross, $count) : 0,
                'customers' => (int) ($customers[$key] ?? 0),
                'books' => (int) ($copies[$key]->books ?? 0),
                'ebooks' => (int) ($copies[$key]->ebooks ?? 0),
            ];
        }

        return $rows;
    }

    /** Printed formats in stock with no copy in a non-cancelled order placed in the last 60 days (owner 2A). */
    private function deadStock(): array
    {
        $since = CarbonImmutable::now()->subDays(self::DEAD_STOCK_DAYS);
        $lastSold = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('order_items.book_variant_id')
            ->selectRaw('order_items.book_variant_id, MAX(orders.placed_at) AS last_sold_at');

        $base = DB::table('book_variants as v')
            ->join('books as b', 'b.id', '=', 'v.book_id')
            ->leftJoinSub($lastSold, 's', 's.book_variant_id', '=', 'v.id')
            ->whereNull('b.deleted_at')
            ->where('v.is_active', true)
            ->whereIn('v.format', self::PRINTED)
            ->where('v.stock_quantity', '>', 0)
            ->where(fn ($q) => $q->whereNull('s.last_sold_at')->orWhere('s.last_sold_at', '<', $since));

        $sum = (clone $base)->selectRaw('COUNT(*) AS n, COALESCE(SUM(v.stock_quantity), 0) AS copies, COALESCE(SUM(v.stock_quantity * v.price_usd), 0) AS value')->first();
        $items = (clone $base)
            ->selectRaw('v.id, v.book_id, b.title, v.format, v.sku, v.stock_quantity, v.stock_quantity * v.price_usd AS value, s.last_sold_at')
            ->orderByDesc('value')->orderBy('v.id')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'book_variant_id' => (int) $r->id,
                'book_id' => (int) $r->book_id,
                'title' => $r->title,
                'format' => $r->format,
                'sku' => $r->sku,
                'stock_quantity' => (int) $r->stock_quantity,
                'value_usd' => Money::format(Money::toCents($r->value)),
                'last_sold_at' => $r->last_sold_at !== null ? CarbonImmutable::parse($r->last_sold_at)->toIso8601ZuluString() : null,
            ])
            ->all();
        $value = Money::toCents($sum->value);

        return [
            'days' => self::DEAD_STOCK_DAYS,
            'variants' => (int) $sum->n,
            'copies' => (int) $sum->copies,
            'value_usd' => Money::format($value),
            'value_khr' => $this->khr($value),
            'items' => $items,
        ];
    }

    /** Printed, active variants of books not in the trash: copies in stock and their value at selling price (owner 3A). */
    private function stockValue(): array
    {
        $row = DB::table('book_variants as v')
            ->join('books as b', 'b.id', '=', 'v.book_id')
            ->whereNull('b.deleted_at')->where('v.is_active', true)->whereIn('v.format', self::PRINTED)->where('v.stock_quantity', '>', 0)
            ->selectRaw('COALESCE(SUM(v.stock_quantity), 0) AS copies, COALESCE(SUM(v.stock_quantity * v.price_usd), 0) AS value')
            ->first();
        $value = Money::toCents($row->value);

        return ['copies' => (int) $row->copies, 'value_usd' => Money::format($value), 'value_khr' => $this->khr($value), 'basis' => 'selling_price'];
    }

    /** Top 10 authors by copies in orders placed in the period (cancelled orders left out). */
    private function topAuthors(CarbonImmutable $start, CarbonImmutable $end): array
    {
        return $this->placedItems($start, $end)
            ->join('book_variants', 'book_variants.id', '=', 'order_items.book_variant_id')
            ->join('book_authors', 'book_authors.book_id', '=', 'book_variants.book_id')
            ->join('authors', 'authors.id', '=', 'book_authors.author_id')
            ->groupBy('authors.id', 'authors.name')
            ->selectRaw('authors.id, authors.name, SUM(order_items.quantity) AS copies, SUM(order_items.subtotal) AS sales')
            ->orderByDesc('copies')->orderBy('authors.id')
            ->limit(10)
            ->get()
            ->map(fn ($r) => [
                'author_id' => (int) $r->id,
                'name' => $r->name,
                'copies_sold' => (int) $r->copies,
                'sales_usd' => Money::format(Money::toCents($r->sales)),
                'sales_khr' => $this->khr(Money::toCents($r->sales)),
            ])
            ->all();
    }

    /** Top 10 provinces by orders placed in the period (owner 4A), from each order's address snapshot. */
    private function provinces(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->placedOrders($start, $end)
            ->groupBy('shipping_city', 'shipping_state')
            ->selectRaw('shipping_city, shipping_state, COUNT(*) AS n, SUM(total_amount) AS total')
            ->get();

        $byProvince = [];
        foreach ($rows as $r) {
            $province = DeliveryArea::detectProvince($r->shipping_city, $r->shipping_state);
            $key = $province['code'] ?? 'unknown';
            $byProvince[$key] ??= ['code' => $province['code'] ?? null, 'name' => $province['name_en'] ?? 'Unknown', 'name_km' => $province['name_km'] ?? null, 'orders' => 0, 'cents' => 0];
            $byProvince[$key]['orders'] += (int) $r->n;
            $byProvince[$key]['cents'] += Money::toCents($r->total);
        }
        usort($byProvince, fn ($a, $b) => [$b['orders'], $b['cents'], $a['name']] <=> [$a['orders'], $a['cents'], $b['name']]);

        return array_map(fn ($p) => [
            'code' => $p['code'],
            'name' => $p['name'],
            'name_km' => $p['name_km'],
            'orders' => $p['orders'],
            'total_usd' => Money::format($p['cents']),
            'total_khr' => $this->khr($p['cents']),
        ], array_slice($byProvince, 0, 10));
    }

    /** Orders placed in the period with an offer, with a coupon, and with neither (offers and coupons never stack). */
    private function promotions(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $row = $this->placedOrders($start, $end)->selectRaw(
            'COUNT(*) FILTER (WHERE offer_id IS NOT NULL OR offer_name IS NOT NULL) AS offer_orders, '
            .'COALESCE(SUM(discount_amount) FILTER (WHERE offer_id IS NOT NULL OR offer_name IS NOT NULL), 0) AS offer_discount, '
            .'COUNT(*) FILTER (WHERE coupon_id IS NOT NULL) AS coupon_orders, '
            .'COALESCE(SUM(discount_amount) FILTER (WHERE coupon_id IS NOT NULL), 0) AS coupon_discount, '
            .'COUNT(*) FILTER (WHERE free_gift IS NOT NULL) AS gift_orders, '
            .'COUNT(*) AS orders'
        )->first();
        $offer = Money::toCents($row->offer_discount);
        $coupon = Money::toCents($row->coupon_discount);

        return [
            'orders' => (int) $row->orders,
            'offer_orders' => (int) $row->offer_orders,
            'offer_discount_usd' => Money::format($offer),
            'coupon_orders' => (int) $row->coupon_orders,
            'coupon_discount_usd' => Money::format($coupon),
            'gift_orders' => (int) $row->gift_orders,
            'plain_orders' => (int) $row->orders - (int) $row->offer_orders - (int) $row->coupon_orders,
        ];
    }

    /** Only reminders with a complete seven-day observation window are included. */
    private function cartReminderRecovery(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $base = DB::table('marketing_outbox as reminder')->where('reminder.kind', 'cart_reminder')
            ->where('reminder.status', 'sent')->whereNotNull('reminder.customer_id')
            ->where('reminder.sent_at', '>=', $start->utc())->where('reminder.sent_at', '<', $end->utc())
            ->where('reminder.sent_at', '<=', now()->subDays(7));
        $sent = (clone $base)->count();
        $recovered = (clone $base)->whereExists(function ($query) {
            $query->selectRaw('1')->from('orders')
                ->whereColumn('orders.customer_id', 'reminder.customer_id')
                ->whereNull('orders.deleted_at')->where('orders.status', '!=', OrderStatus::Cancelled->value)
                ->whereColumn('orders.placed_at', '>=', 'reminder.sent_at')
                ->whereRaw("orders.placed_at < reminder.sent_at + interval '7 days'");
        })->count();

        return ['sent' => $sent, 'recovered' => $recovered, 'percent' => $sent ? round(100 * $recovered / $sent, 1) : 0.0];
    }

    /** Tender allocations at placement; prepaid credit is not another external payment. */
    private function paymentMethods(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->placedOrders($start, $end)->groupBy('payment_method')
            ->selectRaw('payment_method, COUNT(*) FILTER (WHERE total_amount * 100 > credit_used_cents) AS orders, COALESCE(SUM(total_amount - credit_used_cents / 100.0), 0) AS total, COALESCE(SUM(credit_used_cents), 0) AS credit, COUNT(*) FILTER (WHERE credit_used_cents > 0) AS credit_orders')->get();
        $credit = (int) $rows->sum('credit');
        $creditOrders = (int) $rows->sum('credit_orders');
        $rows = $rows->filter(fn ($row) => (int) $row->orders > 0)->values();
        if ($credit > 0) {
            $rows->push((object) ['payment_method' => 'wallet', 'orders' => $creditOrders, 'total' => Money::format($credit)]);
        }
        $total = $rows->sum(fn ($row) => Money::toCents($row->total));

        return $rows->map(fn ($row) => [
            'method' => $row->payment_method,
            'orders' => (int) $row->orders,
            'total_usd' => Money::format(Money::toCents($row->total)),
            'percent' => $total > 0 ? round(100 * Money::toCents($row->total) / $total, 1) : 0.0,
        ])->sortBy('method')->values()->all();
    }

    /** Of the customers who placed an order in the period, the share who had also ordered before it. 0.0-100.0. */
    private function repeatCustomerRate(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $buyers = $this->placedOrders($start, $end)->distinct()->pluck('customer_id');
        $returning = $buyers->isEmpty() ? 0 : DB::table('orders')
            ->whereNull('deleted_at')->where('status', '!=', OrderStatus::Cancelled->value)
            ->whereIn('customer_id', $buyers)->where('placed_at', '<', $start->utc())
            ->distinct()->count('customer_id');

        return [
            'customers' => $buyers->count(),
            'returning' => $returning,
            'percent' => $buyers->isEmpty() ? 0.0 : round($returning / $buyers->count() * 100, 1),
        ];
    }

    private function placedOrders(CarbonImmutable $start, CarbonImmutable $end)
    {
        return DB::table('orders')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->whereBetween('orders.placed_at', [$start->utc(), $end->utc()->subSecond()]);
    }

    private function placedItems(CarbonImmutable $start, CarbonImmutable $end)
    {
        return DB::table('order_items')->joinSub($this->placedOrders($start, $end)->select('orders.id'), 'o', 'o.id', '=', 'order_items.order_id');
    }

    private function khr(int $cents): ?string
    {
        return $this->currency->usdToKhr(Money::format($cents));
    }
}

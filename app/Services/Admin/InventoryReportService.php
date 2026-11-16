<?php

namespace App\Services\Admin;

use App\Enums\BookFormat;
use App\Enums\InventoryReason;
use App\Enums\OrderStatus;
use App\Services\CurrencyService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Inventory health (today, not a date range): what the shelves are worth, what is about to run out and what is
 * not selling. Only printed, active formats of books not in the trash.
 *
 * - Value at cost = copies x the weighted average restock cost of that format (formats without a cost are counted
 *   in copies_without_cost and left out of the cost value).
 * - Value at selling price = copies x today's price.
 * - Sales speed = copies in non-cancelled orders placed in the last `days` days, per day.
 * - Runs out in = copies in stock / sales speed (only formats that sold in the window).
 * - Not selling = in stock, no order in the last 90 days.
 */
class InventoryReportService
{
    public const DEAD_DAYS = 90;

    public const COVER_DAYS = 30;

    private const PRINTED = [BookFormat::Hardcover->value, BookFormat::Paperback->value];

    public function __construct(private readonly CurrencyService $currency) {}

    public function report(int $days = 30): array
    {
        return Cache::remember("report:inventory:{$days}", now()->addMinutes(5), function () use ($days) {
            $rows = $this->variants($days);
            $inStock = $rows->filter(fn ($r) => $r['stock'] > 0);

            $costValue = $inStock->sum(fn ($r) => $r['unit_cost_cents'] === null ? 0 : $r['unit_cost_cents'] * $r['stock']);
            $retailValue = $inStock->sum(fn ($r) => $r['price_cents'] * $r['stock']);
            $costedRetail = $inStock->filter(fn ($r) => $r['unit_cost_cents'] !== null)->sum(fn ($r) => $r['price_cents'] * $r['stock']);
            $dead = $inStock->filter(fn ($r) => $r['dead']);
            $deadValue = $dead->sum(fn ($r) => $r['price_cents'] * $r['stock']);

            return [
                'as_of' => now()->toIso8601ZuluString(),
                'sales_days' => $days,
                'dead_days' => self::DEAD_DAYS,
                'cover_days' => self::COVER_DAYS,
                'totals' => [
                    'formats_in_stock' => $inStock->count(),
                    'copies' => (int) $inStock->sum('stock'),
                    'copies_without_cost' => (int) $inStock->whereNull('unit_cost_cents')->sum('stock'),
                    'value_cost_usd' => Money::format($costValue),
                    'value_cost_khr' => $this->khr($costValue),
                    'value_retail_usd' => Money::format($retailValue),
                    'value_retail_khr' => $this->khr($retailValue),
                    'potential_profit_usd' => Money::format($costedRetail - $costValue),
                    'potential_profit_khr' => $this->khr($costedRetail - $costValue),
                    'low_stock' => $rows->filter(fn ($r) => $r['stock'] > 0 && $r['stock'] <= $r['threshold'])->count(),
                    'out_of_stock' => $rows->filter(fn ($r) => $r['stock'] <= 0)->count(),
                    'runs_out_soon' => $rows->filter(fn ($r) => $r['days_left'] !== null && $r['days_left'] <= 14)->count(),
                    'dead_formats' => $dead->count(),
                    'dead_copies' => (int) $dead->sum('stock'),
                    'dead_value_usd' => Money::format($deadValue),
                    'dead_value_khr' => $this->khr($deadValue),
                ],
                'runs_out' => $rows
                    ->filter(fn ($r) => $r['days_left'] !== null && $r['days_left'] <= self::COVER_DAYS)
                    ->sortBy([fn ($a, $b) => $a['days_left'] <=> $b['days_left'], fn ($a, $b) => $b['sold'] <=> $a['sold']])
                    ->take(20)->values()
                    ->map(fn ($r) => $this->row($r) + [
                        'sold' => $r['sold'],
                        'per_day' => round($r['per_day'], 2),
                        'days_left' => $r['days_left'],
                        'suggested_buy' => max(0, (int) ceil($r['per_day'] * self::COVER_DAYS) - max(0, $r['stock'])),
                    ])->all(),
                'not_selling' => $dead
                    ->sortBy([fn ($a, $b) => ($b['price_cents'] * $b['stock']) <=> ($a['price_cents'] * $a['stock']), fn ($a, $b) => $a['variant_id'] <=> $b['variant_id']])
                    ->take(20)->values()
                    ->map(fn ($r) => $this->row($r) + [
                        'value_usd' => Money::format($r['price_cents'] * $r['stock']),
                        'last_sold_at' => $r['last_sold_at'],
                    ])->all(),
                'by_category' => $this->byCategory($inStock),
            ];
        });
    }

    /** Runs-out days per variant (for the purchase list), using the same sales speed. */
    public function daysLeft(int $days = 30): array
    {
        return $this->variants($days)->mapWithKeys(fn ($r) => [$r['variant_id'] => ['sold' => $r['sold'], 'days_left' => $r['days_left']]])->all();
    }

    public function csvRows(array $report): array
    {
        $t = $report['totals'];
        $rows = [
            ['Metric', 'Value'],
            ['Copies in stock', $t['copies']],
            ['Value at cost (USD)', $t['value_cost_usd']],
            ['Copies without a cost', $t['copies_without_cost']],
            ['Value at selling price (USD)', $t['value_retail_usd']],
            ['Potential profit (USD)', $t['potential_profit_usd']],
            ['Low stock formats', $t['low_stock']],
            ['Out of stock formats', $t['out_of_stock']],
            ["Not selling ({$report['dead_days']} days) value (USD)", $t['dead_value_usd']],
            [],
            ['Runs out soon', 'Format', 'SKU', 'In stock', "Sold last {$report['sales_days']} days", 'Days left', 'Suggested buy'],
        ];
        foreach ($report['runs_out'] as $r) {
            $rows[] = [$r['title'], $r['format'], $r['sku'], $r['stock_quantity'], $r['sold'], $r['days_left'], $r['suggested_buy']];
        }
        $rows[] = [];
        $rows[] = ['Not selling', 'Format', 'SKU', 'In stock', 'Value (USD)', 'Last sold'];
        foreach ($report['not_selling'] as $r) {
            $rows[] = [$r['title'], $r['format'], $r['sku'], $r['stock_quantity'], $r['value_usd'], $r['last_sold_at'] ?? 'Never'];
        }

        return $rows;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function variants(int $days): Collection
    {
        $since = now()->subDays($days);
        $deadSince = now()->subDays(self::DEAD_DAYS);

        $sales = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('order_items.book_variant_id')
            ->selectRaw('order_items.book_variant_id, MAX(orders.placed_at) AS last_sold_at, COALESCE(SUM(CASE WHEN orders.placed_at >= ? THEN order_items.quantity ELSE 0 END), 0) AS sold', [$since]);

        $costs = DB::table('inventory_movements')
            ->where('reason', InventoryReason::Restock->value)
            ->where('change_qty', '>', 0)
            ->whereNotNull('unit_cost_usd')
            ->groupBy('book_variant_id')
            ->selectRaw('book_variant_id, SUM(change_qty * unit_cost_usd) / SUM(change_qty) AS avg_cost');

        return DB::table('book_variants as v')
            ->join('books as b', 'b.id', '=', 'v.book_id')
            ->leftJoinSub($sales, 's', 's.book_variant_id', '=', 'v.id')
            ->leftJoinSub($costs, 'c', 'c.book_variant_id', '=', 'v.id')
            ->whereNull('b.deleted_at')
            ->where('v.is_active', true)
            ->whereIn('v.format', self::PRINTED)
            ->get(['v.id', 'v.book_id', 'b.title', 'v.format', 'v.sku', 'v.stock_quantity', 'v.low_stock_threshold', 'v.price_usd', 's.sold', 's.last_sold_at', 'c.avg_cost'])
            ->map(function ($r) use ($days, $deadSince) {
                $stock = (int) $r->stock_quantity;
                $sold = (int) ($r->sold ?? 0);
                $perDay = $sold / $days;
                $lastSold = $r->last_sold_at !== null ? CarbonImmutable::parse($r->last_sold_at) : null;

                return [
                    'variant_id' => (int) $r->id,
                    'book_id' => (int) $r->book_id,
                    'title' => $r->title,
                    'format' => $r->format,
                    'sku' => $r->sku,
                    'stock' => $stock,
                    'threshold' => (int) $r->low_stock_threshold,
                    'price_cents' => Money::toCents($r->price_usd),
                    'unit_cost_cents' => $r->avg_cost !== null ? (int) round((float) $r->avg_cost * 100) : null,
                    'sold' => $sold,
                    'per_day' => $perDay,
                    'days_left' => $perDay > 0 ? (int) floor(max(0, $stock) / $perDay) : null,
                    'dead' => $stock > 0 && ($lastSold === null || $lastSold->lt($deadSince)),
                    'last_sold_at' => $lastSold?->toIso8601ZuluString(),
                ];
            });
    }

    private function row(array $r): array
    {
        return [
            'book_variant_id' => $r['variant_id'],
            'book_id' => $r['book_id'],
            'title' => $r['title'],
            'format' => $r['format'],
            'sku' => $r['sku'],
            'stock_quantity' => $r['stock'],
        ];
    }

    private function byCategory(Collection $inStock): array
    {
        if ($inStock->isEmpty()) {
            return [];
        }
        $categories = DB::table('book_categories')
            ->join('categories', 'categories.id', '=', 'book_categories.category_id')
            ->whereIn('book_categories.book_id', $inStock->pluck('book_id')->unique()->all())
            ->get(['book_categories.book_id', 'categories.id', 'categories.name'])
            ->groupBy('book_id');

        $rows = [];
        foreach ($inStock as $v) {
            foreach ($categories[$v['book_id']] ?? [] as $cat) {
                $rows[$cat->id] ??= ['category_id' => (int) $cat->id, 'name' => $cat->name, 'copies' => 0, 'retail' => 0, 'cost' => 0];
                $rows[$cat->id]['copies'] += $v['stock'];
                $rows[$cat->id]['retail'] += $v['price_cents'] * $v['stock'];
                $rows[$cat->id]['cost'] += ($v['unit_cost_cents'] ?? 0) * $v['stock'];
            }
        }

        return collect($rows)
            ->sortBy([fn ($a, $b) => $b['retail'] <=> $a['retail'], fn ($a, $b) => $a['category_id'] <=> $b['category_id']])
            ->values()
            ->map(fn ($r) => [
                'category_id' => $r['category_id'],
                'name' => $r['name'],
                'copies' => $r['copies'],
                'value_retail_usd' => Money::format($r['retail']),
                'value_cost_usd' => Money::format($r['cost']),
            ])
            ->all();
    }

    private function khr(int $cents): ?string
    {
        return $this->currency->usdToKhr(Money::format($cents));
    }
}

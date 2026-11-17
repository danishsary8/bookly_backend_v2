<?php

namespace App\Services\Admin;

use App\Enums\BookFormat;
use App\Enums\InventoryReason;
use App\Enums\OrderStatus;
use App\Enums\ReturnStatus;
use App\Services\CurrencyService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Profit report: what restocking cost, what the books sold for, and what was left.
 *
 * How the numbers are made:
 * - Spending = restocks made in the range: copies x unit cost (restocks without a cost are counted, not priced).
 * - Sales = book lines of orders delivered in the range, after every discount on that line (no delivery fees).
 * - Cost of a sold copy = the weighted average unit cost of that format's restocks up to the end of the range.
 *   Ebooks and audiobooks have no stock cost, so their cost is 0.
 * - Gross profit and margin only use lines whose cost is known; the rest is listed under "cost unknown".
 * - After refunds = gross profit - refunds paid in the range + the cost of the returned copies (they go back on the shelf).
 */
class ProfitReportService
{
    public function __construct(private readonly CurrencyService $currency) {}

    public function report(CarbonImmutable $start, CarbonImmutable $end, string $group = 'day'): array
    {
        $cacheKey = "report:profit:{$start->toDateString()}:{$end->toDateString()}:{$group}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($start, $end, $group) {
            $lines = $this->soldLines($start, $end);
            $now = $this->totals($start, $end, $lines);

            $days = (int) $start->diffInDays($end);
            [$prevStart, $prevEnd] = [$start->subDays($days), $start];
            $before = $this->totals($prevStart, $prevEnd);

            return [
                'period' => [
                    'from' => $start->toDateString(),
                    'to' => $end->subDay()->toDateString(),
                    'group' => $group,
                    'timezone' => $start->timezoneName,
                ],
                'totals' => $this->formatTotals($now),
                'comparison' => [
                    'period' => [
                        'from' => $prevStart->toDateString(),
                        'to' => $prevEnd->subDay()->toDateString(),
                        'timezone' => $start->timezoneName,
                    ],
                    'totals' => $this->formatTotals($before),
                    'change_percent' => [
                        'restock_spend' => $this->change($now['spend'], $before['spend']),
                        'sales' => $this->change($now['sales'], $before['sales']),
                        'cost_of_goods' => $this->change($now['cost'], $before['cost']),
                        'gross_profit' => $this->change($now['sales'] - $now['cost'], $before['sales'] - $before['cost']),
                        'profit_after_refunds' => $this->change($this->afterRefunds($now), $this->afterRefunds($before)),
                    ],
                ],
                'series' => $this->series($start, $end, $group),
                'by_supplier' => $this->bySupplier($start, $end),
                'by_category' => $this->byCategory($lines),
                'top_books' => $this->topBooks($lines),
                'cost_unknown' => $this->costUnknown($lines),
            ];
        });
    }

    /** CSV rows: one per series bucket. */
    public function csvRows(array $report): array
    {
        $rows = [['Period / Date', 'Restock Spend (USD)', 'Book Sales With Known Cost (USD)', 'Cost of Books Sold (USD)', 'Gross Profit (USD)', 'Margin (%)']];
        foreach ($report['series'] as $row) {
            $rows[] = [$row['date'], $row['restock_spend_usd'], $row['sales_usd'], $row['cost_of_goods_usd'], $row['gross_profit_usd'], $row['margin_percent'] ?? ''];
        }
        $t = $report['totals'];
        $rows[] = [];
        $rows[] = ['Total', $t['restock_spend_usd'], $t['sales_usd'], $t['cost_of_goods_usd'], $t['gross_profit_usd'], $t['margin_percent'] ?? ''];
        $rows[] = ['Sales with unknown cost (USD)', $t['sales_cost_unknown_usd']];
        $rows[] = ['Refunds (USD)', $t['refunds_usd']];
        $rows[] = ['Cost of returned copies (USD)', $t['returned_cost_usd']];
        $rows[] = ['Profit after refunds (USD)', $t['profit_after_refunds_usd']];

        return $rows;
    }

    // ---------------------------------------------------------------- totals

    /** @return array{spend:int, restock_units:int, restock_units_without_cost:int, sales:int, cost:int, units_costed:int, sales_unknown:int, units_unknown:int, refunds:int, returned_cost:int} */
    private function totals(CarbonImmutable $start, CarbonImmutable $end, ?Collection $lines = null): array
    {
        [$from, $to] = [$start->utc(), $end->utc()];

        $restock = DB::table('inventory_movements')
            ->where('reason', InventoryReason::Restock->value)
            ->where('change_qty', '>', 0)
            ->whereBetween('created_at', [$from, $to->subSecond()])
            ->selectRaw('COALESCE(SUM(change_qty * unit_cost_usd), 0) AS spend, COALESCE(SUM(change_qty), 0) AS units, COALESCE(SUM(CASE WHEN unit_cost_usd IS NULL THEN change_qty ELSE 0 END), 0) AS units_without_cost')
            ->first();

        $sales = 0;
        $cost = 0;
        $unitsCosted = 0;
        $salesUnknown = 0;
        $unitsUnknown = 0;
        foreach ($lines ?? $this->soldLines($start, $end) as $line) {
            if ($line['unit_cost_cents'] === null) {
                $salesUnknown += $line['sales_cents'];
                $unitsUnknown += $line['units'];

                continue;
            }
            $sales += $line['sales_cents'];
            $cost += $line['cost_cents'];
            $unitsCosted += $line['units'];
        }

        $refunds = Money::toCents(DB::table('returns')
            ->where('status', ReturnStatus::Refunded->value)
            ->whereBetween('resolved_at', [$from, $to->subSecond()])
            ->sum('refund_amount'));

        return [
            'spend' => Money::toCents($restock->spend),
            'restock_units' => (int) $restock->units,
            'restock_units_without_cost' => (int) $restock->units_without_cost,
            'sales' => $sales,
            'cost' => $cost,
            'units_costed' => $unitsCosted,
            'sales_unknown' => $salesUnknown,
            'units_unknown' => $unitsUnknown,
            'refunds' => $refunds,
            'returned_cost' => $this->returnedCost($start, $end),
        ];
    }

    private function afterRefunds(array $t): int
    {
        return $t['sales'] - $t['cost'] - $t['refunds'] + $t['returned_cost'];
    }

    private function formatTotals(array $t): array
    {
        $profit = $t['sales'] - $t['cost'];
        $after = $this->afterRefunds($t);

        return [
            'restock_spend_usd' => Money::format($t['spend']),
            'restock_spend_khr' => $this->khr($t['spend']),
            'restock_units' => $t['restock_units'],
            'restock_units_without_cost' => $t['restock_units_without_cost'],
            'sales_usd' => Money::format($t['sales']),
            'sales_khr' => $this->khr($t['sales']),
            'cost_of_goods_usd' => Money::format($t['cost']),
            'cost_of_goods_khr' => $this->khr($t['cost']),
            'gross_profit_usd' => Money::format($profit),
            'gross_profit_khr' => $this->khr($profit),
            'margin_percent' => $this->margin($profit, $t['sales']),
            'units_sold_costed' => $t['units_costed'],
            'sales_cost_unknown_usd' => Money::format($t['sales_unknown']),
            'sales_cost_unknown_khr' => $this->khr($t['sales_unknown']),
            'units_sold_cost_unknown' => $t['units_unknown'],
            'refunds_usd' => Money::format($t['refunds']),
            'refunds_khr' => $this->khr($t['refunds']),
            'returned_cost_usd' => Money::format($t['returned_cost']),
            'returned_cost_khr' => $this->khr($t['returned_cost']),
            'profit_after_refunds_usd' => Money::format($after),
            'profit_after_refunds_khr' => $this->khr($after),
        ];
    }

    // ---------------------------------------------------------------- lines

    /**
     * Sold lines grouped per format (variant) for orders delivered in the range.
     *
     * @return Collection<int, array{variant_id:int, book_id:int, title:string, format:string, units:int, sales_cents:int, unit_cost_cents:?int, cost_cents:int}>
     */
    private function soldLines(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        [$from, $to] = [$start->utc(), $end->utc()];
        $costs = $this->unitCosts($to);

        return DB::table('order_items')
            ->join('book_variants', 'book_variants.id', '=', 'order_items.book_variant_id')
            ->join('books', 'books.id', '=', 'book_variants.book_id')
            ->whereIn('order_items.order_id', $this->deliveredOrderIds($from, $to))
            ->groupBy('book_variants.id', 'books.id', 'books.title', 'book_variants.format')
            ->selectRaw('book_variants.id AS variant_id, books.id AS book_id, books.title, book_variants.format, SUM(order_items.quantity) AS units, SUM(order_items.subtotal - order_items.discount_amount) AS sales')
            ->get()
            ->map(function ($r) use ($costs) {
                $units = (int) $r->units;
                $unitCost = BookFormat::from($r->format)->isDigital() ? 0 : ($costs[$r->variant_id] ?? null);

                return [
                    'variant_id' => (int) $r->variant_id,
                    'book_id' => (int) $r->book_id,
                    'title' => $r->title,
                    'format' => $r->format,
                    'units' => $units,
                    'sales_cents' => Money::toCents($r->sales),
                    'unit_cost_cents' => $unitCost,
                    'cost_cents' => $unitCost === null ? 0 : $unitCost * $units,
                ];
            });
    }

    /**
     * Weighted average unit cost (cents) per variant from restocks with a cost, up to $to.
     *
     * @return array<int, int>
     */
    private function unitCosts(CarbonImmutable $to): array
    {
        return DB::table('inventory_movements')
            ->where('reason', InventoryReason::Restock->value)
            ->where('change_qty', '>', 0)
            ->whereNotNull('unit_cost_usd')
            ->where('created_at', '<', $to)
            ->groupBy('book_variant_id')
            ->selectRaw('book_variant_id, SUM(change_qty * unit_cost_usd) / SUM(change_qty) AS avg_cost')
            ->pluck('avg_cost', 'book_variant_id')
            ->map(fn ($v) => (int) round((float) $v * 100))
            ->all();
    }

    private function deliveredOrderIds(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return DB::table('orders')
            ->whereNull('orders.deleted_at')
            ->whereExists(fn ($q) => $q->from('order_status_history as h')
                ->whereColumn('h.order_id', 'orders.id')
                ->where('h.status', OrderStatus::Delivered->value)
                ->whereBetween('h.created_at', [$from, $to->subSecond()]))
            ->select('orders.id');
    }

    /** Cost of physical copies in returns refunded in the range (they go back into stock). */
    private function returnedCost(CarbonImmutable $start, CarbonImmutable $end): int
    {
        [$from, $to] = [$start->utc(), $end->utc()];
        $costs = $this->unitCosts($to);

        $rows = DB::table('return_items')
            ->join('returns', 'returns.id', '=', 'return_items.return_id')
            ->join('order_items', 'order_items.id', '=', 'return_items.order_item_id')
            ->where('returns.status', ReturnStatus::Refunded->value)
            ->whereBetween('returns.resolved_at', [$from, $to->subSecond()])
            ->groupBy('order_items.book_variant_id')
            ->selectRaw('order_items.book_variant_id AS variant_id, SUM(return_items.quantity) AS units')
            ->get();

        $total = 0;
        foreach ($rows as $r) {
            $total += ($costs[$r->variant_id] ?? 0) * (int) $r->units;
        }

        return $total;
    }

    // ---------------------------------------------------------------- breakdowns

    private function series(CarbonImmutable $start, CarbonImmutable $end, string $group): array
    {
        [$from, $to] = [$start->utc(), $end->utc()];
        $tz = $start->timezoneName;
        $unit = match ($group) {
            'week' => 'week',
            'month' => 'month',
            default => 'day',
        };
        $bucket = fn (string $column) => "TO_CHAR(DATE_TRUNC('{$unit}', ({$column} AT TIME ZONE 'UTC') AT TIME ZONE ?), 'YYYY-MM-DD')";

        $spend = DB::table('inventory_movements')
            ->where('reason', InventoryReason::Restock->value)
            ->where('change_qty', '>', 0)
            ->whereBetween('created_at', [$from, $to->subSecond()])
            ->selectRaw($bucket('created_at').' AS bucket, COALESCE(SUM(change_qty * unit_cost_usd), 0) AS spend', [$tz])
            ->groupBy('bucket')
            ->pluck('spend', 'bucket');

        // First delivery time per order inside the range decides its bucket.
        $delivered = DB::table('order_status_history')
            ->where('status', OrderStatus::Delivered->value)
            ->whereBetween('created_at', [$from, $to->subSecond()])
            ->groupBy('order_id')
            ->selectRaw('order_id, MIN(created_at) AS delivered_at');

        $costs = $this->unitCosts($to);
        $sold = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->joinSub($delivered, 'd', 'd.order_id', '=', 'orders.id')
            ->join('book_variants', 'book_variants.id', '=', 'order_items.book_variant_id')
            ->whereNull('orders.deleted_at')
            ->selectRaw($bucket('d.delivered_at').' AS bucket, book_variants.id AS variant_id, book_variants.format, SUM(order_items.quantity) AS units, SUM(order_items.subtotal - order_items.discount_amount) AS sales', [$tz])
            ->groupBy('bucket', 'book_variants.id', 'book_variants.format')
            ->get();

        $buckets = [];
        foreach ($sold as $r) {
            $unitCost = BookFormat::from($r->format)->isDigital() ? 0 : ($costs[$r->variant_id] ?? null);
            if ($unitCost === null) {
                continue;
            }
            $buckets[$r->bucket] ??= ['sales' => 0, 'cost' => 0];
            $buckets[$r->bucket]['sales'] += Money::toCents($r->sales);
            $buckets[$r->bucket]['cost'] += $unitCost * (int) $r->units;
        }

        $series = [];
        // Step from the start of the first bucket: adding a month to 31 January would skip February.
        $cursor = match ($unit) {
            'week' => $start->startOfWeek(),
            'month' => $start->startOfMonth(),
            default => $start,
        };
        while ($cursor->lt($end)) {
            $key = $cursor->toDateString();
            $s = $buckets[$key]['sales'] ?? 0;
            $c = $buckets[$key]['cost'] ?? 0;
            $series[] = [
                'date' => $key,
                'restock_spend_usd' => Money::format(Money::toCents($spend[$key] ?? 0)),
                'sales_usd' => Money::format($s),
                'cost_of_goods_usd' => Money::format($c),
                'gross_profit_usd' => Money::format($s - $c),
                'margin_percent' => $this->margin($s - $c, $s),
            ];
            $cursor = match ($unit) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $series;
    }

    private function bySupplier(CarbonImmutable $start, CarbonImmutable $end): array
    {
        [$from, $to] = [$start->utc(), $end->utc()];

        return DB::table('inventory_movements')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'inventory_movements.supplier_id')
            ->where('reason', InventoryReason::Restock->value)
            ->where('change_qty', '>', 0)
            ->whereBetween('inventory_movements.created_at', [$from, $to->subSecond()])
            ->selectRaw("NULLIF(TRIM(supplier), '') AS name, MAX(suppliers.type) AS type, COUNT(*) AS restocks, SUM(change_qty) AS units, COALESCE(SUM(change_qty * unit_cost_usd), 0) AS spend, SUM(CASE WHEN unit_cost_usd IS NULL THEN change_qty ELSE 0 END) AS units_without_cost")
            ->groupByRaw("NULLIF(TRIM(supplier), '')")
            ->orderByDesc('spend')->orderByDesc('units')
            ->get()
            ->map(function ($r) {
                $spend = Money::toCents($r->spend);
                $costedUnits = (int) $r->units - (int) $r->units_without_cost;

                return [
                    'supplier' => $r->name,
                    // publisher | distributor; null for a typed name without a supplier record (owner 2026-11-17).
                    'type' => $r->type,
                    'restocks' => (int) $r->restocks,
                    'units' => (int) $r->units,
                    'units_without_cost' => (int) $r->units_without_cost,
                    'spend_usd' => Money::format($spend),
                    'spend_khr' => $this->khr($spend),
                    'average_unit_cost_usd' => $costedUnits > 0 ? Money::format(intdiv($spend, $costedUnits)) : null,
                ];
            })
            ->all();
    }

    private function byCategory(Collection $lines): array
    {
        $lines = $lines->filter(fn ($l) => $l['unit_cost_cents'] !== null);
        if ($lines->isEmpty()) {
            return [];
        }

        $categories = DB::table('book_categories')
            ->join('categories', 'categories.id', '=', 'book_categories.category_id')
            ->whereIn('book_categories.book_id', $lines->pluck('book_id')->unique()->all())
            ->get(['book_categories.book_id', 'categories.id', 'categories.name'])
            ->groupBy('book_id');

        $rows = [];
        foreach ($lines as $line) {
            foreach ($categories[$line['book_id']] ?? [] as $cat) {
                $rows[$cat->id] ??= ['category_id' => (int) $cat->id, 'name' => $cat->name, 'units' => 0, 'sales' => 0, 'cost' => 0];
                $rows[$cat->id]['units'] += $line['units'];
                $rows[$cat->id]['sales'] += $line['sales_cents'];
                $rows[$cat->id]['cost'] += $line['cost_cents'];
            }
        }

        return collect($rows)
            ->sortBy([fn ($a, $b) => ($b['sales'] - $b['cost']) <=> ($a['sales'] - $a['cost']), fn ($a, $b) => $a['category_id'] <=> $b['category_id']])
            ->values()
            ->map(fn ($r) => $this->profitRow($r, ['category_id' => $r['category_id'], 'name' => $r['name']]))
            ->all();
    }

    private function topBooks(Collection $lines, int $limit = 10): array
    {
        return $lines
            ->filter(fn ($l) => $l['unit_cost_cents'] !== null)
            ->sortBy([fn ($a, $b) => ($b['sales_cents'] - $b['cost_cents']) <=> ($a['sales_cents'] - $a['cost_cents']), fn ($a, $b) => $a['variant_id'] <=> $b['variant_id']])
            ->take($limit)
            ->values()
            ->map(fn ($l) => $this->profitRow(
                ['units' => $l['units'], 'sales' => $l['sales_cents'], 'cost' => $l['cost_cents']],
                ['book_id' => $l['book_id'], 'variant_id' => $l['variant_id'], 'title' => $l['title'], 'format' => $l['format'], 'unit_cost_usd' => Money::format($l['unit_cost_cents'])],
            ))
            ->all();
    }

    private function costUnknown(Collection $lines, int $limit = 20): array
    {
        return $lines
            ->filter(fn ($l) => $l['unit_cost_cents'] === null)
            ->sortBy([fn ($a, $b) => $b['sales_cents'] <=> $a['sales_cents'], fn ($a, $b) => $a['variant_id'] <=> $b['variant_id']])
            ->take($limit)
            ->values()
            ->map(fn ($l) => [
                'book_id' => $l['book_id'],
                'variant_id' => $l['variant_id'],
                'title' => $l['title'],
                'format' => $l['format'],
                'units_sold' => $l['units'],
                'sales_usd' => Money::format($l['sales_cents']),
            ])
            ->all();
    }

    private function profitRow(array $r, array $extra): array
    {
        $profit = $r['sales'] - $r['cost'];

        return $extra + [
            'units_sold' => $r['units'],
            'sales_usd' => Money::format($r['sales']),
            'cost_of_goods_usd' => Money::format($r['cost']),
            'gross_profit_usd' => Money::format($profit),
            'gross_profit_khr' => $this->khr($profit),
            'margin_percent' => $this->margin($profit, $r['sales']),
        ];
    }

    // ---------------------------------------------------------------- helpers

    private function margin(int $profit, int $sales): ?float
    {
        return $sales > 0 ? round($profit / $sales * 100, 1) : null;
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
}

<?php

namespace App\Services\Catalog;

use App\Models\BookVariant;
use App\Models\StaffUser;
use App\Services\Admin\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * Changes the sell price and/or cost price of many formats at once (owner 2026-11-26).
 * Preview and apply share plan(), so what the admin confirms is exactly what is saved.
 * Order: the cost rule runs first, so "sell = cost × markup" uses the new cost.
 * Work is in cents to avoid float drift. Past orders keep their frozen prices.
 */
class BulkPriceChange
{
    public const SELL_MODES = ['keep', 'set', 'percent', 'amount', 'markup'];

    public const COST_MODES = ['keep', 'set', 'percent', 'amount'];

    public const ROUNDING = ['none', '90', '50', 'whole'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{variant_ids: int[], sell_mode: string, sell_value: ?float, cost_mode: string, cost_value: ?float, rounding: string, floor_at_cost: bool}  $rule
     * @return Collection<int, array<string, mixed>>
     */
    public function plan(array $rule, bool $lock = false): Collection
    {
        $query = BookVariant::with('book:id,title')->whereIn('id', $rule['variant_ids'])->whereHas('book')->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->map(fn (BookVariant $v) => $this->row($v, $rule));
    }

    /** @return array<string, mixed> */
    private function row(BookVariant $v, array $rule): array
    {
        $oldPrice = $this->cents($v->price_usd);
        $oldCost = $v->cost_price_usd === null ? null : $this->cents($v->cost_price_usd);
        $note = null;

        $newCost = $oldCost;
        if ($rule['cost_mode'] !== 'keep') {
            $value = (float) $rule['cost_value'];
            $newCost = match ($rule['cost_mode']) {
                'set' => (int) round($value * 100),
                'percent' => $oldCost === null ? null : (int) round($oldCost * (1 + $value / 100)),
                'amount' => $oldCost === null ? null : $oldCost + (int) round($value * 100),
            };
            if ($oldCost === null && $rule['cost_mode'] !== 'set') {
                $note = 'no_cost';
            }
            $newCost = $newCost === null ? null : max(0, $newCost);
        }

        $newPrice = $oldPrice;
        if ($rule['sell_mode'] !== 'keep') {
            $value = (float) $rule['sell_value'];
            $raw = match ($rule['sell_mode']) {
                'set' => $value * 100,
                'percent' => $oldPrice * (1 + $value / 100),
                'amount' => $oldPrice + $value * 100,
                'markup' => $newCost === null ? null : $newCost * $value,
            };
            if ($raw === null) {
                $note = 'no_cost';
            } else {
                $newPrice = $this->round(max(0, (int) round($raw)), $rule['rounding']);
                if ($rule['floor_at_cost'] && $newCost !== null && $newPrice < $newCost) {
                    $newPrice = $oldPrice;
                    $note = 'below_cost';
                }
            }
        }

        return [
            'variant_id' => $v->id,
            'book_id' => $v->book_id,
            'title' => $v->book->title,
            'format' => $v->format->value,
            'sku' => $v->sku,
            'old_price_usd' => $this->usd($oldPrice),
            'new_price_usd' => $this->usd($newPrice),
            'old_cost_usd' => $oldCost === null ? null : $this->usd($oldCost),
            'new_cost_usd' => $newCost === null ? null : $this->usd($newCost),
            'margin_percent' => $newCost === null || $newPrice === 0 ? null : round(($newPrice - $newCost) / $newPrice * 100, 1),
            'changed' => $newPrice !== $oldPrice || $newCost !== $oldCost,
            'note' => $note,
        ];
    }

    /** Nearest price ending in .90 / .50, or the nearest whole dollar; never below the smallest such price. */
    private function round(int $cents, string $mode): int
    {
        return match ($mode) {
            '90' => max(90, (int) (round(($cents + 10) / 100) * 100) - 10),
            '50' => max(50, (int) (round(($cents - 50) / 100) * 100) + 50),
            'whole' => max(100, (int) (round($cents / 100) * 100)),
            default => $cents,
        };
    }

    /** @return Collection<int, array<string, mixed>> */
    public function apply(array $rule, StaffUser $user): Collection
    {
        return DB::transaction(function () use ($rule, $user) {
            $rows = $this->plan($rule, lock: true);
            if ($rows->count() !== count($rule['variant_ids'])) {
                throw ValidationException::withMessages(['variant_ids' => 'Some of these formats belong to deleted books. Reload the list and try again.']);
            }
            $variants = BookVariant::whereIn('id', $rows->pluck('variant_id'))->get()->keyBy('id');
            foreach ($rows->where('changed', true) as $row) {
                $variant = $variants[$row['variant_id']];
                $before = $variant->only(['price_usd', 'cost_price_usd']);
                $variant->price_usd = $row['new_price_usd'];
                $variant->cost_price_usd = $row['new_cost_usd'];
                $variant->save();
                $this->audit->custom($user, 'prices_changed', $variant, $before, $variant->only(['price_usd', 'cost_price_usd']));
            }

            return $rows;
        });
    }

    private function cents(mixed $usd): int
    {
        return (int) round(((float) $usd) * 100);
    }

    private function usd(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}

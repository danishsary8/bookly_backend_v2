<?php

namespace App\Services;

use App\Models\ExchangeRate;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * USD→KHR rates over time, read once per request: printing 50 invoices asks "which rate was in force when this order
 * was placed?" 50 times, with one query. Same answer as ExchangeRate::at('USD', 'KHR', $moment).
 */
class KhrRateHistory
{
    /** @var Collection<int, ExchangeRate>|null newest first */
    private ?Collection $rates = null;

    public function at(DateTimeInterface $moment): ?ExchangeRate
    {
        $this->rates ??= ExchangeRate::query()
            ->where('base_currency', 'USD')
            ->where('target_currency', 'KHR')
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->get();

        return $this->rates->first(fn (ExchangeRate $rate) => $rate->effective_at <= $moment);
    }
}

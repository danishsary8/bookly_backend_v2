<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Support\Money;

/** USD is the only stored currency; KHR is derived from the latest exchange rate. */
class CurrencyService
{
    private ?ExchangeRate $khrRate = null;

    private bool $loaded = false;

    /** Whole riel, or null when no USD->KHR rate has been set yet. */
    public function usdToKhr(string|float|null $usd): ?string
    {
        if ($usd === null) {
            return null;
        }

        $rate = $this->khrRate();

        return $rate ? Money::convert((string) $usd, (string) $rate->rate) : null;
    }

    private function khrRate(): ?ExchangeRate
    {
        if (! $this->loaded) {
            $this->khrRate = ExchangeRate::current('USD', 'KHR');
            $this->loaded = true;
        }

        return $this->khrRate;
    }
}

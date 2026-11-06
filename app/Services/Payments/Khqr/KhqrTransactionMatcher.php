<?php

namespace App\Services\Payments\Khqr;

use App\Support\Money;

final class KhqrTransactionMatcher
{
    /** @param array<string, mixed> $transaction */
    public static function matches(string $amount, string $currency, array $transaction): bool
    {
        return strtoupper((string) ($transaction['currency'] ?? '')) === $currency
            && is_numeric($transaction['amount'] ?? null)
            && Money::toCents((string) $transaction['amount']) === Money::toCents($amount);
    }
}

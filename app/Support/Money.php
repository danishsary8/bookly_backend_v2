<?php

namespace App\Support;

/** Money math in integer cents so DECIMAL(10,2) values never pick up float rounding errors. */
final class Money
{
    public static function toCents(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        $negative = str_starts_with((string) $amount, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim(sprintf('%.2F', abs((float) $amount)), '-')), 2, '0');
        $cents = ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');

        return $negative ? -$cents : $cents;
    }

    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Converts a USD amount to another currency at the given rate, rounded to whole units for KHR. */
    public static function convert(string $amount, string $rate, int $decimals = 0): string
    {
        return number_format(self::toCents($amount) / 100 * (float) $rate, $decimals, '.', '');
    }
}

<?php

namespace App\Http\Resources;

use App\Services\CurrencyService;
use App\Services\Offers\OfferResult;
use App\Support\Money;

/** The JSON pieces about offers that the cart, the coupon check and the checkout preview share (spec 6.1-6.3). */
final class OfferPayload
{
    public static function offer(?OfferResult $result, CurrencyService $currency): ?array
    {
        if ($result === null) {
            return null;
        }

        return [
            'id' => $result->offer->id,
            'type' => 'buy_n_percent',
            'min_quantity' => $result->offer->minQuantity,
            'percent_off' => $result->offer->percentOff,
            'ends_at' => $result->offer->endsAtIso,
            'target_label' => $result->offer->targetLabel,
            ...self::money($result->discountCents, 'discount', $currency),
        ];
    }

    /** @param  array{threshold_cents: int, amount_needed_cents: int, is_free: bool}|null  $free */
    public static function freeShipping(?array $free, CurrencyService $currency): ?array
    {
        if ($free === null) {
            return null;
        }

        return [
            ...self::money($free['threshold_cents'], 'threshold', $currency),
            ...self::money($free['amount_needed_cents'], 'amount_needed', $currency),
            'is_free' => $free['is_free'],
        ];
    }

    /** @return array<string, string|null> e.g. ['discount_usd' => '3.00', 'discount_khr' => '12300'] */
    public static function money(int $cents, string $name, CurrencyService $currency): array
    {
        return [
            "{$name}_usd" => Money::format($cents),
            "{$name}_khr" => $currency->usdToKhr(Money::format($cents)),
        ];
    }
}

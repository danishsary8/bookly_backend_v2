<?php

namespace App\Services\Offers;

/**
 * Spreads an order's discount over its lines (spec 4.7), so a refund can take back exactly the share of the books
 * returned. The result always adds up to the discount, and no line gets more than its own price.
 */
final class LineDiscounts
{
    /**
     * Coupon won: split by line price. Every line gets floor(discount × line total / subtotal); the cents left over
     * from rounding (fewer than the number of lines) go to the last line, or, if the last line is already fully
     * discounted, to the next line before it with room.
     *
     * @param  array<int, int>  $lineTotals  line key => line total in cents, in order
     * @return array<int, int> line key => discount in cents
     */
    public static function forCoupon(array $lineTotals, int $discountCents): array
    {
        $subtotal = array_sum($lineTotals);
        if ($subtotal <= 0 || $discountCents <= 0) {
            return array_map(fn () => 0, $lineTotals);
        }
        $discountCents = min($discountCents, $subtotal);

        $shares = [];
        foreach ($lineTotals as $key => $total) {
            $shares[$key] = intdiv($discountCents * $total, $subtotal);
        }

        $rest = $discountCents - array_sum($shares);
        foreach (array_reverse(array_keys($lineTotals)) as $key) {
            if ($rest === 0) {
                break;
            }
            $room = $lineTotals[$key] - $shares[$key];
            $give = min($room, $rest);
            $shares[$key] += $give;
            $rest -= $give;
        }

        return $shares;
    }

    /**
     * Offer won: each covered line keeps its own discount from the offer math; the others get 0.
     *
     * @param  array<int, int>  $lineTotals  line key => line total in cents
     * @return array<int, int>
     */
    public static function forOffer(array $lineTotals, OfferResult $offer): array
    {
        $shares = [];
        foreach ($lineTotals as $key => $total) {
            $shares[$key] = $offer->lineDiscounts[$key] ?? 0;
        }

        return $shares;
    }
}

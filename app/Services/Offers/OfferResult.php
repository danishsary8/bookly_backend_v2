<?php

namespace App\Services\Offers;

/** What one offer does to one cart. */
final class OfferResult
{
    /** @param  array<int, int>  $lineDiscounts  cart item id => discount in cents (covered lines only) */
    public function __construct(
        public readonly LiveOffer $offer,
        public readonly int $coveredUnits,
        public readonly int $discountCents,
        public readonly array $lineDiscounts,
    ) {}

    public function applies(): bool
    {
        return $this->discountCents > 0;
    }
}

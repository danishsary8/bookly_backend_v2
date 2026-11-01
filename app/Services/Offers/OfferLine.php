<?php

namespace App\Services\Offers;

/** One cart line as the offer math sees it (no models, so the math is easy to test). */
final class OfferLine
{
    /** @param  list<int>  $categoryIds */
    public function __construct(
        public readonly int $key,            // the cart item id
        public readonly int $bookId,
        public readonly array $categoryIds,
        public readonly ?int $seriesId,
        public readonly int $quantity,
        public readonly int $lineTotalCents,
        public readonly bool $blocked = false, // unavailable / out of stock / not enough stock: never counts
    ) {}
}

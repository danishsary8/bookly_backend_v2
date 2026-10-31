<?php

namespace App\Services\Offers;

/**
 * A plain copy of one offer and its usable targets, safe to cache. Targets whose book, category or series is in the
 * trash are already left out; a "selected" offer with no target left matches nothing ("No books").
 */
final class LiveOffer
{
    /**
     * @param  list<int>  $bookIds
     * @param  list<int>  $categoryIds
     * @param  list<int>  $seriesIds
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $appliesToAll,
        public readonly int $minQuantity,
        public readonly string $percentOff,   // "15.00"
        public readonly int $percentBasisPoints, // 15.00 % -> 1500
        public readonly ?int $startsAt,        // unix seconds
        public readonly ?int $endsAt,
        public readonly ?string $endsAtIso,
        public readonly ?int $maxOrders,
        public readonly int $usedCount,
        public readonly array $bookIds = [],
        public readonly array $categoryIds = [],
        public readonly array $seriesIds = [],
        public readonly ?string $targetLabel = null,
    ) {}

    /** Spec 4.1: started, not ended, not used up, and (all books or at least one target left). */
    public function isLiveAt(int $now): bool
    {
        return ($this->startsAt === null || $this->startsAt <= $now)
            && ($this->endsAt === null || $this->endsAt > $now)
            && ($this->maxOrders === null || $this->usedCount < $this->maxOrders)
            && $this->hasBooks();
    }

    public function hasBooks(): bool
    {
        return $this->appliesToAll || $this->bookIds !== [] || $this->categoryIds !== [] || $this->seriesIds !== [];
    }

    /** Spec 4.2: the book is a target, or one of its categories is, or its series is. */
    public function coversBook(int $bookId, array $categoryIds, ?int $seriesId): bool
    {
        if ($this->appliesToAll) {
            return true;
        }

        return in_array($bookId, $this->bookIds, true)
            || ($seriesId !== null && in_array($seriesId, $this->seriesIds, true))
            || array_intersect($categoryIds, $this->categoryIds) !== [];
    }
}

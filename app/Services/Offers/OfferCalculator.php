<?php

namespace App\Services\Offers;

/**
 * The offer math, with no database: docs/superpowers/specs/2026-10-31-offers-design.md sections 4.2, 4.3 and 4.6.
 * All money is integer cents. Callers pass only offers that are live (LiveOffer::isLiveAt).
 */
final class OfferCalculator
{
    /**
     * Spec 4.3. Once the covered units reach N, EVERY covered unit gets X% off, once (not per pair).
     * Each covered line: floor(line total × basis points / 10000); the offer discount is the sum of the lines.
     */
    public function evaluate(LiveOffer $offer, array $lines): OfferResult
    {
        $covered = array_filter($lines, fn (OfferLine $line) => ! $line->blocked
            && $offer->coversBook($line->bookId, $line->categoryIds, $line->seriesId));

        $units = array_sum(array_map(fn (OfferLine $line) => $line->quantity, $covered));

        if ($units < $offer->minQuantity) {
            return new OfferResult($offer, $units, 0, []);
        }

        $lineDiscounts = [];
        foreach ($covered as $line) {
            $lineDiscounts[$line->key] = intdiv($line->lineTotalCents * $offer->percentBasisPoints, 10000);
        }

        return new OfferResult($offer, $units, array_sum($lineDiscounts), $lineDiscounts);
    }

    /**
     * Spec 4.4 step 1: the offer that saves the most; a tie goes to the lower id. Offers that save 0 don't count.
     *
     * @param  list<LiveOffer>  $offers
     * @param  list<OfferLine>  $lines
     */
    public function best(array $offers, array $lines): ?OfferResult
    {
        $best = null;
        foreach ($offers as $offer) {
            $result = $this->evaluate($offer, $lines);
            if (! $result->applies()) {
                continue;
            }
            if ($best === null
                || $result->discountCents > $best->discountCents
                || ($result->discountCents === $best->discountCents && $offer->id < $best->offer->id)) {
                $best = $result;
            }
        }

        return $best;
    }

    /**
     * Spec 4.6: at most one nudge. Candidates: offers the cart has at least 1 covered unit for but fewer than N,
     * and (when an offer already applies) only those with a higher percent, i.e. a higher tier. Pick the one needing
     * the fewest extra units; tie -> higher percent, then lower id.
     *
     * @param  list<LiveOffer>  $offers
     * @param  list<OfferLine>  $lines
     * @return array{offer: LiveOffer, add_quantity: int}|null
     */
    public function nudge(array $offers, array $lines, ?OfferResult $applied = null): ?array
    {
        $pick = null;
        foreach ($offers as $offer) {
            $units = $this->evaluate($offer, $lines)->coveredUnits;
            if ($units < 1 || $units >= $offer->minQuantity) {
                continue;
            }
            if ($applied !== null && $offer->percentBasisPoints <= $applied->offer->percentBasisPoints) {
                continue;
            }

            $candidate = ['offer' => $offer, 'add_quantity' => $offer->minQuantity - $units];
            if ($pick === null || $this->nudgeBeats($candidate, $pick)) {
                $pick = $candidate;
            }
        }

        return $pick;
    }

    /**
     * For a book card or book page (spec 6.6): the best offer covering this book: biggest percent; tie -> lower N,
     * then lower id.
     *
     * @param  list<LiveOffer>  $offers
     */
    public function bestForBook(array $offers, int $bookId, array $categoryIds, ?int $seriesId): ?LiveOffer
    {
        $best = null;
        foreach ($offers as $offer) {
            if (! $offer->coversBook($bookId, $categoryIds, $seriesId)) {
                continue;
            }
            if ($best === null
                || $offer->percentBasisPoints > $best->percentBasisPoints
                || ($offer->percentBasisPoints === $best->percentBasisPoints && $offer->minQuantity < $best->minQuantity)
                || ($offer->percentBasisPoints === $best->percentBasisPoints && $offer->minQuantity === $best->minQuantity && $offer->id < $best->id)) {
                $best = $offer;
            }
        }

        return $best;
    }

    private function nudgeBeats(array $a, array $b): bool
    {
        if ($a['add_quantity'] !== $b['add_quantity']) {
            return $a['add_quantity'] < $b['add_quantity'];
        }
        if ($a['offer']->percentBasisPoints !== $b['offer']->percentBasisPoints) {
            return $a['offer']->percentBasisPoints > $b['offer']->percentBasisPoints;
        }

        return $a['offer']->id < $b['offer']->id;
    }
}

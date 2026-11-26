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
        if ($offer->wholeSeries) {
            return $this->evaluateSet($offer, $lines);
        }

        $covered = array_filter($lines, fn (OfferLine $line) => ! $line->blocked
            && $offer->coversBook($line->bookId, $line->categoryIds, $line->seriesId));

        $units = array_sum(array_map(fn (OfferLine $line) => $line->quantity, $covered));

        if ($units < $offer->minQuantity) {
            return new OfferResult($offer, $units, 0, []);
        }

        if ($offer->type === 'bundle_price') {
            return $this->evaluateBundle($offer, $covered, $units);
        }

        if ($offer->type === 'buy_n_amount') {
            $coveredTotal = array_sum(array_map(fn (OfferLine $line) => $line->lineTotalCents, $covered));
            $totalDiscount = min($offer->amountOffCents, $coveredTotal);
            $lineDiscounts = [];
            $remaining = $totalDiscount;
            $count = count($covered);
            $i = 0;
            foreach ($covered as $line) {
                $i++;
                if ($i === $count) {
                    $lineDiscounts[$line->key] = min($remaining, $line->lineTotalCents);
                } else {
                    $share = $coveredTotal > 0 ? (int) round(($line->lineTotalCents / $coveredTotal) * $totalDiscount) : 0;
                    $share = min($share, $remaining, $line->lineTotalCents);
                    $lineDiscounts[$line->key] = $share;
                    $remaining -= $share;
                }
            }

            return new OfferResult($offer, $units, array_sum($lineDiscounts), $lineDiscounts);
        }

        $lineDiscounts = [];
        foreach ($covered as $line) {
            $lineDiscounts[$line->key] = intdiv($line->lineTotalCents * $offer->percentBasisPoints, 10000);
        }

        return new OfferResult($offer, $units, array_sum($lineDiscounts), $lineDiscounts);
    }

    /**
     * A bundle offer (owner 2026-11-26): "any N covered books for P". The covered copies are put in groups of N,
     * most expensive first (the customer always gets the biggest saving); each full group costs P, unless its books
     * already cost less (then no discount for it: a bundle never raises a price). Copies left over pay full price.
     * Each group's discount is shared over its copies in proportion to their price; the last copy takes the cents left.
     *
     * @param  array<int, OfferLine>  $covered
     */
    private function evaluateBundle(LiveOffer $offer, array $covered, int $units): OfferResult
    {
        $size = max(1, $offer->minQuantity);
        $copies = [];
        foreach ($covered as $line) {
            $quantity = max(1, $line->quantity);
            $each = intdiv($line->lineTotalCents, $quantity);
            $extra = $line->lineTotalCents - $each * $quantity; // cents a split leaves over go to the first copy
            for ($i = 0; $i < $line->quantity; $i++) {
                $copies[] = ['key' => $line->key, 'cents' => $each + ($i === 0 ? $extra : 0)];
            }
        }
        usort($copies, fn (array $a, array $b) => [$b['cents'], $a['key']] <=> [$a['cents'], $b['key']]);

        $lineDiscounts = [];
        $groups = intdiv(count($copies), $size);
        for ($g = 0; $g < $groups; $g++) {
            $group = array_slice($copies, $g * $size, $size);
            $sum = array_sum(array_column($group, 'cents'));
            $discount = $sum - $offer->bundlePriceCents;
            if ($discount <= 0) {
                continue;
            }
            $left = $discount;
            foreach ($group as $i => $copy) {
                $share = min($copy['cents'], $i === $size - 1 ? $left : intdiv($copy['cents'] * $discount, $sum));
                $left -= $share;
                $lineDiscounts[$copy['key']] = ($lineDiscounts[$copy['key']] ?? 0) + $share;
            }
        }

        return new OfferResult($offer, $units, array_sum($lineDiscounts), $lineDiscounts);
    }

    /**
     * A set offer (owner 2026-11-12): a target series counts only when EVERY one of its books on sale is in the cart
     * (blocked lines never count); then every line of that series gets X% off. Several complete series add up.
     */
    private function evaluateSet(LiveOffer $offer, array $lines): OfferResult
    {
        $units = 0;
        $lineDiscounts = [];
        foreach ($offer->seriesBooks as $seriesId => $bookIds) {
            $inSeries = array_filter($lines, fn (OfferLine $line) => ! $line->blocked && $line->seriesId === $seriesId);
            $have = array_unique(array_map(fn (OfferLine $line) => $line->bookId, $inSeries));
            if ($bookIds === [] || array_diff($bookIds, $have) !== []) {
                continue;
            }
            foreach ($inSeries as $line) {
                $units += $line->quantity;
                $lineDiscounts[$line->key] = intdiv($line->lineTotalCents * $offer->percentBasisPoints, 10000);
            }
        }

        return new OfferResult($offer, $units, array_sum($lineDiscounts), $lineDiscounts);
    }

    /** Set offers: how many volumes the cart still misses of the series it is closest to completing (0: none started). */
    private function missingForSet(LiveOffer $offer, array $lines): int
    {
        $least = 0;
        foreach ($offer->seriesBooks as $seriesId => $bookIds) {
            $have = [];
            foreach ($lines as $line) {
                if (! $line->blocked && $line->seriesId === $seriesId) {
                    $have[] = $line->bookId;
                }
            }
            $missing = count(array_diff($bookIds, $have));
            if ($have !== [] && $missing > 0 && ($least === 0 || $missing < $least)) {
                $least = $missing;
            }
        }

        return $least;
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
            if ($offer->wholeSeries) {
                // "Add 2 more volumes to complete the set": a started series that is not complete yet.
                $add = $this->missingForSet($offer, $lines);
            } else {
                $units = $this->evaluate($offer, $lines)->coveredUnits;
                $add = $units < 1 || $units >= $offer->minQuantity ? 0 : $offer->minQuantity - $units;
            }
            if ($add < 1) {
                continue;
            }
            if ($applied !== null && $offer->percentBasisPoints <= $applied->offer->percentBasisPoints) {
                continue;
            }

            $candidate = ['offer' => $offer, 'add_quantity' => $add];
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

<?php

namespace Tests\Unit\Offers;

use App\Services\Offers\LiveOffer;
use App\Services\Offers\OfferCalculator;
use App\Services\Offers\OfferLine;
use PHPUnit\Framework\TestCase;

/** The offer math with no database (spec docs/superpowers/specs/2026-10-31-offers-design.md, sections 4 and 5). */
class OfferCalculatorTest extends TestCase
{
    private const HOLMES = 9;

    private const COOKING = 5;

    private OfferCalculator $calc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new OfferCalculator;
    }

    private function offer(int $id, int $n, int $basisPoints, array $targets = [], ?string $label = null): LiveOffer
    {
        return new LiveOffer(
            id: $id, name: "Offer {$id}", appliesToAll: $targets === [], minQuantity: $n,
            percentOff: sprintf('%d.%02d', intdiv($basisPoints, 100), $basisPoints % 100), percentBasisPoints: $basisPoints,
            startsAt: null, endsAt: null, endsAtIso: null, maxOrders: null, usedCount: 0,
            bookIds: $targets['books'] ?? [], categoryIds: $targets['categories'] ?? [], seriesIds: $targets['series'] ?? [],
            targetLabel: $label,
        );
    }

    private function holmesOffer(int $id = 1, int $n = 2, int $basisPoints = 1500): LiveOffer
    {
        return $this->offer($id, $n, $basisPoints, ['series' => [self::HOLMES]], 'Sherlock Holmes');
    }

    private function line(int $key, int $cents, int $qty = 1, ?int $series = null, array $categories = [], bool $blocked = false): OfferLine
    {
        return new OfferLine($key, 100 + $key, $categories, $series, $qty, $cents * $qty, $blocked);
    }

    /** Example 1 (owner's): Holmes A $10 + Holmes B $10 + cookbook $30, "Buy 2 Holmes, get 15%". */
    public function test_example_1_only_the_covered_lines_are_discounted(): void
    {
        $lines = [
            $this->line(1, 1000, series: self::HOLMES),
            $this->line(2, 1000, series: self::HOLMES),
            $this->line(3, 3000, categories: [self::COOKING]),
        ];

        $result = $this->calc->evaluate($this->holmesOffer(), $lines);

        $this->assertSame(2, $result->coveredUnits);
        $this->assertSame(300, $result->discountCents);
        $this->assertSame([1 => 150, 2 => 150], $result->lineDiscounts);
    }

    /** Example 3: 2 paperbacks at $10 + an ebook at $7.99; ebooks count; rounding is down per line. */
    public function test_example_3_all_units_and_ebooks_count_and_each_line_rounds_down(): void
    {
        $lines = [
            $this->line(1, 1000, qty: 2, series: self::HOLMES),
            $this->line(2, 799, series: self::HOLMES), // the ebook line
        ];

        $result = $this->calc->evaluate($this->holmesOffer(), $lines);

        $this->assertSame(3, $result->coveredUnits);
        $this->assertSame([1 => 300, 2 => 119], $result->lineDiscounts); // 119.85 -> 119
        $this->assertSame(419, $result->discountCents);
    }

    public function test_three_units_with_buy_2_discounts_all_three_not_per_pair(): void
    {
        $lines = [$this->line(1, 1000), $this->line(2, 1000), $this->line(3, 1000)];

        $result = $this->calc->evaluate($this->offer(1, 2, 1000), $lines);

        $this->assertSame([1 => 100, 2 => 100, 3 => 100], $result->lineDiscounts);
        $this->assertSame(300, $result->discountCents);
    }

    /** Example 4, offer side: the offer is worth exactly $3.00; two offers worth the same -> the lower id wins. */
    public function test_example_4_tie_between_offers_goes_to_the_lower_id(): void
    {
        $lines = [$this->line(1, 1000, series: self::HOLMES), $this->line(2, 1000, series: self::HOLMES)];
        $later = $this->holmesOffer(id: 7);
        $earlier = $this->holmesOffer(id: 3);

        $this->assertSame(300, $this->calc->evaluate($later, $lines)->discountCents);
        $this->assertSame(3, $this->calc->best([$later, $earlier], $lines)->offer->id);
        $this->assertSame(3, $this->calc->best([$earlier, $later], $lines)->offer->id);
    }

    public function test_the_offer_that_saves_the_most_wins(): void
    {
        $lines = [
            $this->line(1, 1000, series: self::HOLMES),
            $this->line(2, 1000, series: self::HOLMES),
            $this->line(3, 3000),
        ];
        $everything10 = $this->offer(1, 2, 1000);      // 10% of $50 = $5.00
        $holmes15 = $this->holmesOffer(id: 2);        // 15% of $20 = $3.00

        $best = $this->calc->best([$holmes15, $everything10], $lines);

        $this->assertSame(1, $best->offer->id);
        $this->assertSame(500, $best->discountCents);
    }

    public function test_no_offer_applies_below_n(): void
    {
        $lines = [$this->line(1, 1000, series: self::HOLMES), $this->line(2, 3000)];

        $this->assertSame(0, $this->calc->evaluate($this->holmesOffer(), $lines)->discountCents);
        $this->assertNull($this->calc->best([$this->holmesOffer()], $lines));
    }

    public function test_blocked_lines_never_count_and_never_get_a_discount(): void
    {
        // Holmes B is out of stock: only 1 covered unit, so no offer.
        $lines = [$this->line(1, 1000, series: self::HOLMES), $this->line(2, 1000, series: self::HOLMES, blocked: true)];
        $this->assertSame(1, $this->calc->evaluate($this->holmesOffer(), $lines)->coveredUnits);
        $this->assertNull($this->calc->best([$this->holmesOffer()], $lines));

        // With 2 good Holmes lines the offer applies, and the blocked line still gets nothing.
        $lines[] = $this->line(3, 1000, series: self::HOLMES);
        $result = $this->calc->evaluate($this->holmesOffer(), $lines);
        $this->assertSame([1 => 150, 3 => 150], $result->lineDiscounts);
    }

    public function test_selected_offer_matches_by_book_category_or_series(): void
    {
        $byBook = $this->offer(1, 2, 1000, ['books' => [101]]);
        $byCategory = $this->offer(2, 2, 1000, ['categories' => [self::COOKING]]);
        $bySeries = $this->offer(3, 2, 1000, ['series' => [self::HOLMES]]);

        $this->assertTrue($byBook->coversBook(101, [], null));
        $this->assertFalse($byBook->coversBook(102, [self::COOKING], self::HOLMES));
        $this->assertTrue($byCategory->coversBook(102, [1, self::COOKING], null));
        $this->assertFalse($byCategory->coversBook(102, [1], null));
        $this->assertTrue($bySeries->coversBook(102, [], self::HOLMES));
        $this->assertFalse($bySeries->coversBook(102, [], null));
    }

    /** Example 5: Holmes A only -> "add 1 more from Sherlock Holmes to save 15%". */
    public function test_example_5_nudge_needs_one_more(): void
    {
        $nudge = $this->calc->nudge([$this->holmesOffer()], [$this->line(1, 1000, series: self::HOLMES)]);

        $this->assertSame(1, $nudge['add_quantity']);
        $this->assertSame(1, $nudge['offer']->id);
        $this->assertSame('1500', (string) $nudge['offer']->percentBasisPoints);
        $this->assertSame('Sherlock Holmes', $nudge['offer']->targetLabel);
    }

    public function test_nudge_skips_offers_with_no_covered_book_and_picks_the_fewest_extra_books(): void
    {
        $lines = [$this->line(1, 1000, series: self::HOLMES)];
        $cooking = $this->offer(1, 2, 2000, ['categories' => [self::COOKING]]); // nothing of it in the cart
        $holmes3 = $this->holmesOffer(id: 2, n: 3, basisPoints: 2500);       // needs 2 more
        $holmes2 = $this->holmesOffer(id: 3, n: 2, basisPoints: 1000);       // needs 1 more

        $nudge = $this->calc->nudge([$cooking, $holmes3, $holmes2], $lines);

        $this->assertSame(3, $nudge['offer']->id);
        $this->assertSame(1, $nudge['add_quantity']);
        $this->assertNull($this->calc->nudge([$cooking], $lines));
    }

    public function test_nudge_to_a_higher_tier_only(): void
    {
        $lines = [$this->line(1, 1000, series: self::HOLMES), $this->line(2, 1000, series: self::HOLMES)];
        $tier1 = $this->holmesOffer(id: 1, n: 2, basisPoints: 1500);
        $tier2 = $this->holmesOffer(id: 2, n: 3, basisPoints: 2000);
        $lower = $this->holmesOffer(id: 3, n: 3, basisPoints: 1000);

        $applied = $this->calc->best([$tier1, $tier2, $lower], $lines);
        $nudge = $this->calc->nudge([$tier1, $tier2, $lower], $lines, $applied);

        $this->assertSame(1, $applied->offer->id);
        $this->assertSame(2, $nudge['offer']->id);
        $this->assertSame(1, $nudge['add_quantity']);
        $this->assertNull($this->calc->nudge([$tier1, $lower], $lines, $applied));
    }

    public function test_best_offer_for_a_book_card(): void
    {
        $all10 = $this->offer(1, 2, 1000);
        $holmes15n3 = $this->holmesOffer(id: 2, n: 3);
        $holmes15n2 = $this->holmesOffer(id: 4, n: 2);
        $holmes15n2Later = $this->holmesOffer(id: 5, n: 2);
        $offers = [$all10, $holmes15n3, $holmes15n2Later, $holmes15n2];

        $this->assertSame(4, $this->calc->bestForBook($offers, 101, [], self::HOLMES)->id); // 15%, lower N, lower id
        $this->assertSame(1, $this->calc->bestForBook($offers, 101, [], null)->id);         // only "all books" covers it
        $this->assertNull($this->calc->bestForBook([$holmes15n2], 101, [], null));
    }

    /**
     * Never more than X%: 200 random carts (fixed seed, so the test is repeatable). For every offer the discount is
     * never negative, never above the sum of floor(X% of each covered line), never above X% of the covered subtotal
     * or the subtotal, each line's discount is at most that line's price, and the lines add up to the total.
     */
    public function test_random_carts_never_get_more_than_x_percent(): void
    {
        mt_srand(20261031);
        $discounted = 0;

        for ($cart = 0; $cart < 200; $cart++) {
            $lines = [];
            $lineCount = mt_rand(1, 6);
            for ($i = 1; $i <= $lineCount; $i++) {
                $lines[] = new OfferLine(
                    key: $i,
                    bookId: mt_rand(1, 8),
                    categoryIds: array_values(array_unique([mt_rand(1, 4), mt_rand(1, 4)])),
                    seriesId: mt_rand(0, 2) === 0 ? null : mt_rand(1, 3),
                    quantity: $qty = mt_rand(1, 10),
                    lineTotalCents: mt_rand(1, 5000) * $qty,
                    blocked: mt_rand(1, 10) === 1,
                );
            }

            $offers = [];
            for ($o = 1; $o <= 3; $o++) {
                $targets = match (mt_rand(0, 3)) {
                    0 => [],
                    1 => ['books' => [mt_rand(1, 8)]],
                    2 => ['categories' => [mt_rand(1, 4)]],
                    default => ['series' => [mt_rand(1, 3)]],
                };
                $offers[] = $this->offer($o, mt_rand(2, 20), mt_rand(1, 5000), $targets);
            }

            $subtotal = array_sum(array_map(fn (OfferLine $l) => $l->blocked ? 0 : $l->lineTotalCents, $lines));
            $bestSeen = 0;

            foreach ($offers as $offer) {
                $result = $this->calc->evaluate($offer, $lines);
                $covered = array_filter($lines, fn (OfferLine $l) => ! $l->blocked && $offer->coversBook($l->bookId, $l->categoryIds, $l->seriesId));
                $floorSum = array_sum(array_map(fn (OfferLine $l) => intdiv($l->lineTotalCents * $offer->percentBasisPoints, 10000), $covered));
                $coveredSubtotal = array_sum(array_map(fn (OfferLine $l) => $l->lineTotalCents, $covered));
                $why = "cart {$cart}, offer {$offer->id}";

                $this->assertGreaterThanOrEqual(0, $result->discountCents, $why);
                $this->assertLessThanOrEqual($floorSum, $result->discountCents, $why);
                $this->assertLessThanOrEqual($coveredSubtotal * $offer->percentBasisPoints, $result->discountCents * 10000, $why);
                $this->assertLessThanOrEqual($subtotal, $result->discountCents, $why);
                $this->assertSame($result->discountCents, array_sum($result->lineDiscounts), $why);

                $byKey = [];
                foreach ($lines as $l) {
                    $byKey[$l->key] = $l;
                }
                foreach ($result->lineDiscounts as $key => $cents) {
                    $this->assertFalse($byKey[$key]->blocked, $why);
                    $this->assertLessThanOrEqual($byKey[$key]->lineTotalCents, $cents, $why);
                }

                $bestSeen = max($bestSeen, $result->discountCents);
                $discounted += $result->discountCents > 0 ? 1 : 0;
            }

            $this->assertSame($bestSeen, $this->calc->best($offers, $lines)?->discountCents ?? 0, "cart {$cart}: best");
        }

        // The random carts must really test discounts, not mostly "no offer" (600 offer checks in total).
        $this->assertGreaterThan(100, $discounted, "only {$discounted} of 600 offer checks gave a discount");
    }
}

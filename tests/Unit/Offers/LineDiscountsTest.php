<?php

namespace Tests\Unit\Offers;

use App\Services\Offers\LineDiscounts;
use App\Services\Offers\LiveOffer;
use App\Services\Offers\OfferResult;
use PHPUnit\Framework\TestCase;

/** Spreading an order's discount over its lines (spec 4.7). */
class LineDiscountsTest extends TestCase
{
    public function test_example_2_coupon_split(): void
    {
        $this->assertSame([1 => 100, 2 => 100, 3 => 300], LineDiscounts::forCoupon([1 => 1000, 2 => 1000, 3 => 3000], 500));
    }

    public function test_the_rounding_rest_goes_to_the_last_line(): void
    {
        $this->assertSame([1 => 333, 2 => 333, 3 => 334], LineDiscounts::forCoupon([1 => 1000, 2 => 1000, 3 => 1000], 1000));
    }

    public function test_a_fully_discounted_last_line_passes_the_rest_to_the_line_before(): void
    {
        // 1 cent + 1 cent + 1 cent with 2 cents off: floor shares 0, 0, 0; the rest (2) goes to line 3, then line 2.
        $this->assertSame([1 => 0, 2 => 1, 3 => 1], LineDiscounts::forCoupon([1 => 1, 2 => 1, 3 => 1], 2));
    }

    public function test_no_discount_or_empty_order(): void
    {
        $this->assertSame([1 => 0, 2 => 0], LineDiscounts::forCoupon([1 => 1000, 2 => 500], 0));
        $this->assertSame([], LineDiscounts::forCoupon([], 500));
    }

    public function test_offer_split_keeps_each_lines_own_discount(): void
    {
        $offer = new LiveOffer(1, 'x', true, 2, '15.00', 1500, null, null, null, null, 0);
        $result = new OfferResult($offer, 2, 300, [1 => 150, 2 => 150]);

        $this->assertSame([1 => 150, 2 => 150, 3 => 0], LineDiscounts::forOffer([1 => 1000, 2 => 1000, 3 => 3000], $result));
    }

    /** 200 random orders (fixed seed): always adds up exactly, never negative, never more than a line's price. */
    public function test_random_orders_always_add_up(): void
    {
        mt_srand(20261031);

        for ($order = 0; $order < 200; $order++) {
            $lines = [];
            foreach (range(1, mt_rand(1, 8)) as $key) {
                $lines[$key * 7] = mt_rand(1, 3) === 1 ? mt_rand(1, 20) : mt_rand(1, 50000); // some tiny lines too
            }
            $subtotal = array_sum($lines);
            $discount = mt_rand(0, $subtotal);

            $shares = LineDiscounts::forCoupon($lines, $discount);

            $this->assertSame(array_keys($lines), array_keys($shares), "order {$order}");
            $this->assertSame($discount, array_sum($shares), "order {$order}: must add up");
            foreach ($shares as $key => $cents) {
                $this->assertGreaterThanOrEqual(0, $cents, "order {$order}");
                $this->assertLessThanOrEqual($lines[$key], $cents, "order {$order}: line {$key} over its price");
            }
        }
    }
}

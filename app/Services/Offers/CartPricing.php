<?php

namespace App\Services\Offers;

use App\Models\Book;
use App\Services\Orders\DeliveryArea;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * The offer side of a cart (spec 6.1): the best live offer, the nudge, which lines it discounts, and the free-delivery
 * numbers. Coupons are not part of it (Q5 A: the coupon comparison stays in /cart/coupon/check).
 */
class CartPricing
{
    public function __construct(
        private readonly OfferService $offers,
        private readonly OfferCalculator $calculator,
    ) {}

    /**
     * @param  array  $summary  CartService::summary()
     * @return array{subtotal_cents: int, discount_cents: int, offer: ?OfferResult, nudge: ?array, in_offer: array<int, bool>, free_shipping: ?array}
     */
    public function forSummary(array $summary): array
    {
        $live = $this->offers->live();
        $offer = null;
        $nudge = null;

        if ($live !== []) {
            $lines = $this->offerLines($summary['lines']);
            $offer = $this->calculator->best($live, $lines);
            $nudge = $this->calculator->nudge($live, $lines, $offer);
        }

        return $this->shape($summary, $offer, $nudge);
    }

    /** The live offer that saves the most on this cart, or null (spec 4.4 step 1). Used by checkout totals. */
    public function bestOffer(array $summary): ?OfferResult
    {
        $live = $this->offers->live();

        return $live === [] ? null : $this->calculator->best($live, $this->offerLines($summary['lines']));
    }

    private function shape(array $summary, ?OfferResult $offer, ?array $nudge): array
    {
        $subtotal = $summary['subtotal_cents'];
        $discount = $offer?->discountCents ?? 0;

        $inOffer = [];
        foreach ($summary['lines'] as $line) {
            $inOffer[$line['item']->id] = $offer !== null && array_key_exists($line['item']->id, $offer->lineDiscounts);
        }

        return [
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'offer' => $offer,
            'nudge' => $nudge,
            'in_offer' => $inOffer,
            'free_shipping' => self::freeShipping($summary['lines'], $subtotal - $discount),
        ];
    }

    /**
     * Same rule as checkout (CheckoutService::totals): only when the cart has a printed book and free delivery is on;
     * judged on subtotal minus discount.
     *
     * @return array{threshold_cents: int, amount_needed_cents: int, is_free: bool}|null
     */
    public static function freeShipping($lines, int $netSubtotalCents): ?array
    {
        $requiresShipping = $lines->contains(fn ($line) => ! $line['item']->variant->format->isDigital());
        $threshold = DeliveryArea::freeShippingThreshold();

        if (! $requiresShipping || $threshold === null) {
            return null;
        }

        $thresholdCents = Money::toCents($threshold);
        $net = max(0, $netSubtotalCents);

        return [
            'threshold_cents' => $thresholdCents,
            'amount_needed_cents' => max(0, $thresholdCents - $net),
            'is_free' => $net >= $thresholdCents,
        ];
    }

    /**
     * Cart lines for the math. The books' categories are loaded in ONE query for the whole cart (none when there is
     * no live offer), so the number of queries never depends on the number of offers.
     *
     * @return list<OfferLine>
     */
    private function offerLines($summaryLines): array
    {
        $books = new EloquentCollection(
            $summaryLines->map(fn ($line) => $line['item']->variant->book)->filter()->unique('id')->values()->all(),
        );
        $books->loadMissing(['categories' => fn ($q) => $q->select('categories.id')]);

        $lines = [];
        foreach ($summaryLines as $line) {
            /** @var Book|null $book */
            $book = $line['item']->variant->book;
            if ($book === null) {
                continue; // a line without a book is blocked anyway
            }

            $lines[] = new OfferLine(
                key: $line['item']->id,
                bookId: $book->id,
                categoryIds: $book->categories->pluck('id')->map(fn ($id) => (int) $id)->all(),
                seriesId: $book->series_id !== null ? (int) $book->series_id : null,
                quantity: $line['item']->quantity,
                lineTotalCents: $line['line_total_cents'],
                blocked: $line['blocked'],
            );
        }

        return $lines;
    }
}

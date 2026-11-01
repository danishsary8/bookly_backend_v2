<?php

namespace App\Services\Offers;

use App\Models\Coupon;

/**
 * Offer vs coupon, never both (spec 4.4): the bigger discount in cents wins; a tie goes to the offer, so the coupon
 * stays unused for a later order. Store credit is a coupon like any other (owner Q4 A).
 * The ONLY place this choice is made: checkout totals (preview and placing an order) and the coupon check use it.
 */
final class DiscountChoice
{
    public const OFFER = 'offer';

    public const COUPON = 'coupon';

    private function __construct(
        public readonly ?string $applied,          // 'offer' | 'coupon' | null
        public readonly int $discountCents,
        public readonly ?OfferResult $offer,       // the best live offer for the cart, won or not
        public readonly ?Coupon $coupon,           // only when the coupon won
        public readonly ?Coupon $couponNotUsed,    // a valid coupon that lost to the offer
    ) {}

    public static function pick(?OfferResult $offer, ?Coupon $coupon, int $couponCents): self
    {
        $offerCents = $offer?->discountCents ?? 0;
        $hasOffer = $offer !== null && $offerCents > 0;
        $hasCoupon = $coupon !== null;

        if ($hasOffer && (! $hasCoupon || $offerCents >= $couponCents)) {
            return new self(self::OFFER, $offerCents, $offer, null, $coupon);
        }

        if ($hasCoupon) {
            return new self(self::COUPON, $couponCents, $hasOffer ? $offer : null, $coupon, null);
        }

        return new self(null, 0, null, null, null);
    }

    public function winningOffer(): ?OfferResult
    {
        return $this->applied === self::OFFER ? $this->offer : null;
    }

    /** @return array{code: string, reason: string}|null */
    public function couponNotUsedPayload(): ?array
    {
        return $this->couponNotUsed === null ? null : ['code' => $this->couponNotUsed->code, 'reason' => 'offer_better'];
    }
}

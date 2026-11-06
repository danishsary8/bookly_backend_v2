<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Models\BookVariant;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffUser;
use App\Notifications\LowStockNotification;
use App\Notifications\OrderPlacedNotification;
use App\Services\Cart\CartService;
use App\Services\Cart\CouponService;
use App\Services\Credit\CreditWallet;
use App\Services\Credit\Referrals;
use App\Services\Inventory\InventoryService;
use App\Services\Offers\CartPricing;
use App\Services\Offers\DiscountChoice;
use App\Services\Offers\LineDiscounts;
use App\Services\Offers\LiveOffer;
use App\Services\Offers\OfferService;
use App\Services\Offers\PurchaseGifts;
use App\Services\Payments\KhqrPaymentService;
use App\Services\Telegram\CustomerOrderUpdates;
use App\Services\Telegram\OpsAlerts;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(
        private readonly CartService $carts,
        private readonly CouponService $coupons,
        private readonly InventoryService $inventory,
        private readonly CartPricing $pricing,
        private readonly OfferService $offerService,
    ) {}

    /**
     * Totals for the current cart, without placing anything. Delivery is priced for the given address,
     * else the customer's default address, else the provinces fee (never quote less than checkout charges).
     */
    public function quote(Customer $customer, ?string $couponCode = null, ?int $addressId = null, ?string $deliveryArea = null, bool $useCredit = false): array
    {
        $addresses = CustomerAddress::where('customer_id', $customer->id);
        $address = $addressId !== null
            ? ((clone $addresses)->find($addressId) ?? throw ValidationException::withMessages(['address_id' => __('messages.checkout_choose_address')]))
            : (clone $addresses)->where('is_default', true)->first();

        $hasAddress = $address !== null;
        if ($address === null && $deliveryArea !== null) {
            $address = new CustomerAddress(['city' => $deliveryArea === 'phnom_penh' ? 'Phnom Penh' : 'Provinces']);
        }

        $totals = $this->totals($customer, $this->carts->summary($customer), $couponCode, $address);
        $balance = app(CreditWallet::class)->balance($customer);
        $credit = $useCredit && $this->creditEnabled() ? min($balance, $totals['total_cents']) : 0;

        return [...$totals, 'has_delivery_address' => $hasAddress, 'credit_balance_cents' => $balance, 'credit_used_cents' => $credit, 'payment_due_cents' => $totals['total_cents'] - $credit];
    }

    /**
     * Places a cash-on-delivery order from the customer's cart.
     *
     * @return array{order: Order, created: bool} created=false when the idempotency key was already used
     */
    public function place(Customer $customer, int $addressId, ?string $couponCode, string $idempotencyKey, ?string $deliveryNote = null, ?int $expectedOfferId = null, PaymentMethod $paymentMethod = PaymentMethod::Cod, bool $isGift = false, ?string $giftMessage = null, bool $useCredit = false, ?int $expectedPaymentDueCents = null): array
    {
        $crossedLowStock = [];

        $result = DB::transaction(function () use ($customer, $addressId, $couponCode, $idempotencyKey, $deliveryNote, $expectedOfferId, $paymentMethod, $isGift, $giftMessage, $useCredit, $expectedPaymentDueCents, &$crossedLowStock) {
            // 1. Lock the cart: concurrent checkouts by the same customer run one after another.
            $cart = $this->carts->cartFor($customer);
            Cart::whereKey($cart->id)->lockForUpdate()->first();

            // 2. Same key again (double click, retry after a timeout): return the order already placed.
            $existing = Order::where('customer_id', $customer->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return ['order' => $existing, 'created' => false];
            }

            $address = CustomerAddress::where('customer_id', $customer->id)->find($addressId)
                ?? throw ValidationException::withMessages(['address_id' => __('messages.checkout_choose_address')]);

            // 3. Lock every format in the cart, always in id order so two checkouts cannot deadlock.
            $variantIds = $cart->items()->orderBy('book_variant_id')->pluck('book_variant_id');
            $variants = BookVariant::whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $variants->load(['book' => fn ($q) => $q->withTrashed()->with('authors')]);

            // 4. Re-check the cart with today's prices and stock now that nothing can change underneath us.
            $summary = $this->carts->summary($customer);
            if ($summary['lines']->isEmpty()) {
                throw ValidationException::withMessages(['cart' => __('messages.checkout_cart_empty')]);
            }
            if (! $summary['can_checkout']) {
                throw ValidationException::withMessages(['cart' => __('messages.checkout_cart_problems')]);
            }

            if ($couponCode !== null && $couponCode !== '') {
                Coupon::where('code', CouponService::normalize($couponCode))->lockForUpdate()->first();
            }
            $live = $this->lockBestLiveOffer($summary);
            $freeGift = app(PurchaseGifts::class)->select($summary, reserve: true);
            $totals = $this->totals($customer, $summary, $couponCode, $address, $live, $freeGift, true);
            $offer = $totals['choice']->winningOffer();

            // O1 = A: the customer saw this offer; if it no longer wins (ended, used up, paused), place nothing.
            if ($expectedOfferId !== null && $offer?->offer->id !== $expectedOfferId) {
                throw ValidationException::withMessages(['offer_id' => __('messages.offer_ended')]);
            }

            $lineTotals = $summary['lines']->mapWithKeys(fn ($line) => [$line['item']->id => $line['line_total_cents']])->all();
            $lineDiscounts = $offer !== null
                ? LineDiscounts::forOffer($lineTotals, $offer)
                : LineDiscounts::forCoupon($lineTotals, $totals['coupon'] ? $totals['discount_cents'] - $totals['referral_discount_cents'] : 0);
            $remaining = [];
            foreach ($lineTotals as $id => $amount) {
                $remaining[$id] = $amount - ($lineDiscounts[$id] ?? 0);
            }
            foreach (LineDiscounts::forCoupon($remaining, $totals['referral_discount_cents']) as $id => $amount) {
                $lineDiscounts[$id] = ($lineDiscounts[$id] ?? 0) + $amount;
            }

            $isWaitingStock = false;
            $preorderDates = [];
            foreach ($summary['lines'] as $line) {
                $v = $variants[$line['item']->book_variant_id];
                if ($v->isPreorder()) {
                    $isWaitingStock = true;
                    if ($v->release_date) {
                        $preorderDates[] = $v->release_date->format('Y-m-d');
                    }
                } elseif (! $v->format->isDigital() && ($v->isBackorder() || $v->stock_quantity < $line['item']->quantity)) {
                    $isWaitingStock = true;
                }
            }
            $expectedShipDate = $preorderDates !== [] ? max($preorderDates) : null;
            $wallet = app(CreditWallet::class);
            $credit = $useCredit && $this->creditEnabled() ? min($wallet->balance($customer, lock: true), $totals['total_cents']) : 0;
            if ($useCredit && $expectedPaymentDueCents !== null && $expectedPaymentDueCents !== $totals['total_cents'] - $credit) {
                throw ValidationException::withMessages(['use_credit' => __('credit.balance_changed')]);
            }
            // A fully funded order needs no QR or cash collection; its journal is the payment evidence.
            $fullyFunded = $credit > 0 && $credit === $totals['total_cents'];

            // 5. Create the order with a copy of the address and today's prices.
            $order = Order::create([
                'customer_id' => $customer->id,
                'free_gift' => $freeGift,
                'order_number' => OrderNumber::generate(),
                'idempotency_key' => $idempotencyKey,
                'shipping_address_id' => $address->id,
                'shipping_recipient_name' => $address->recipient_name,
                'shipping_phone' => $address->phone,
                'shipping_address_line1' => $address->address_line1,
                'shipping_address_line2' => $address->address_line2,
                'shipping_city' => $address->city,
                'shipping_state' => $address->state,
                'shipping_postal_code' => $address->postal_code,
                'shipping_country' => $address->country,
                'shipping_latitude' => $address->latitude,
                'shipping_longitude' => $address->longitude,
                'coupon_id' => $totals['coupon']?->id,
                // A copy of the offer at order time, so reports stay right after it is edited or deleted.
                'offer_id' => $offer?->offer->id,
                'offer_name' => $offer?->offer->name,
                'offer_min_quantity' => $offer?->offer->minQuantity,
                'offer_percent_off' => $offer?->offer->percentOff,
                'status' => $fullyFunded ? OrderStatus::Paid : OrderStatus::Pending,
                'subtotal' => Money::format($totals['subtotal_cents']),
                'discount_amount' => Money::format($totals['discount_cents']),
                'shipping_fee' => Money::format($totals['shipping_cents']),
                'tax_amount' => '0.00',
                'total_amount' => Money::format($totals['total_cents']),
                'credit_used_cents' => $credit,
                'referral_discount_cents' => $totals['referral_discount_cents'],
                'currency' => 'USD',
                'payment_method' => $paymentMethod,
                'placed_at' => now(),
                'delivery_note' => $deliveryNote,
                'is_gift' => $isGift,
                'gift_message' => $isGift ? $giftMessage : null,
                'is_waiting_stock' => $isWaitingStock,
                'expected_ship_date' => $expectedShipDate,
            ]);

            if ($credit > 0) {
                $wallet->move($customer, -$credit, 'order:'.$order->id);
            }
            app(Referrals::class)->placed($order);

            foreach ($summary['lines'] as $line) {
                $item = $line['item'];
                $variant = $variants[$item->book_variant_id];

                $order->items()->create([
                    'book_variant_id' => $variant->id,
                    'quantity' => $item->quantity,
                    'unit_price' => $variant->price_usd,
                    'subtotal' => Money::format($line['line_total_cents']),
                    'discount_amount' => Money::format($lineDiscounts[$item->id] ?? 0),
                    ...OrderItem::detailsOf($variant),
                ]);

                if (! $variant->format->isDigital() && $this->inventory->deductForSale($variant, $item->quantity, $order)) {
                    $crossedLowStock[] = $variant;
                }
            }

            if ($totals['coupon']) {
                $totals['coupon']->increment('used_count');
            }
            if ($offer !== null) {
                Offer::whereKey($offer->offer->id)->increment('used_count'); // the row is locked (lockBestLiveOffer)
            }

            $order->statusHistory()->create(['status' => $order->status, 'note' => 'Order placed.']);
            if (! $fullyFunded && $paymentMethod === PaymentMethod::Khqr) {
                app(KhqrPaymentService::class)->start($order); // the first QR (USD); the order waits for payment
            } elseif (! $fullyFunded) {
                $order->payments()->create([
                    'provider' => PaymentProvider::Cod,
                    'amount' => $order->externalPaymentAmount(),
                    'currency' => 'USD',
                    'status' => PaymentStatus::Pending,
                ]);
            }

            $cart->items()->delete();

            return ['order' => $order, 'created' => true];
        });

        if ($result['created']) {
            // A KHQR order announces itself when it is paid (KhqrPaymentService), not before.
            if ($paymentMethod !== PaymentMethod::Khqr || $result['order']->status === OrderStatus::Paid) {
                self::announce($result['order']);
            }

            if ($crossedLowStock !== []) {
                $staff = StaffUser::all();
                foreach ($crossedLowStock as $variant) {
                    Notification::send($staff, LowStockNotification::for($variant->load('book')));
                    app(OpsAlerts::class)->lowStock($variant);
                }
            }
        }

        return $result;
    }

    private function creditEnabled(): bool
    {
        return (bool) config('shop.gift_cards_enabled') || (bool) config('shop.referrals_enabled');
    }

    /**
     * THE totals function: the preview (quote) and placing an order (place) both use it, so they cannot differ.
     * Offer vs coupon is decided by DiscountChoice (better one wins, tie -> offer). A coupon that is wrong, expired or
     * needs a bigger order is still refused exactly as before, even when an offer would win anyway.
     */
    /**
     * Inside the checkout transaction: read the live offers fresh, lock the row of the one that would win and check it
     * is still live under the lock (another checkout may just have used its last slot). If it is not, drop it and try
     * the next best. Only the winning offer's row is locked, so checkouts without offers never wait on each other.
     *
     * @return list<LiveOffer> the offers totals() may use
     */
    private function lockBestLiveOffer(array $summary): array
    {
        $live = $this->offerService->liveFresh();

        while (($best = $this->pricing->bestOffer($summary, $live)) !== null) {
            $row = Offer::whereKey($best->offer->id)->lockForUpdate()->first();
            if ($row !== null && $this->stillLive($row)) {
                return $live;
            }
            $live = array_values(array_filter($live, fn (LiveOffer $o) => $o->id !== $best->offer->id));
        }

        return $live;
    }

    private function stillLive(Offer $row): bool
    {
        return $row->is_active
            && ($row->starts_at === null || ! $row->starts_at->isFuture())
            && ($row->ends_at === null || $row->ends_at->isFuture())
            && ($row->max_orders === null || $row->used_count < $row->max_orders);
    }

    /** @param  list<LiveOffer>|null  $live  checkout passes the offers it locked; the preview uses the cached list */
    private function totals(Customer $customer, array $summary, ?string $couponCode, ?CustomerAddress $address, ?array $live = null, ?array $freeGift = null, bool $giftReserved = false): array
    {
        if (! $giftReserved) {
            $freeGift = app(PurchaseGifts::class)->select($summary);
        }
        $subtotal = $summary['subtotal_cents'];
        $coupon = null;
        $couponDiscount = 0;

        if ($couponCode !== null && $couponCode !== '') {
            if ($subtotal === 0) {
                throw ValidationException::withMessages(['coupon_code' => 'Add items to your cart before using a coupon.']);
            }
            try {
                ['coupon' => $coupon, 'discount_cents' => $couponDiscount] = $this->coupons->evaluate($customer, $couponCode, $subtotal);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['coupon_code' => $e->errors()['code'] ?? $e->getMessage()]);
            }
        }

        $choice = DiscountChoice::pick($this->pricing->bestOffer($summary, $live), $coupon, $couponDiscount);
        $discount = $choice->discountCents;
        $coupon = $choice->coupon;
        $referralDiscount = app(Referrals::class)->discount($customer, $subtotal - $discount, $address, lock: $giftReserved);
        $discount += $referralDiscount;

        $requiresShipping = $freeGift !== null || $summary['lines']->contains(fn ($line) => ! $line['item']->variant->format->isDigital());
        $area = DeliveryArea::for($address);
        $province = $address ? DeliveryArea::detectProvince($address->city, $address->state) : null;
        $provinceCode = $province['code'] ?? null;
        $standardShipping = $requiresShipping ? Money::toCents(DeliveryArea::fee($area, $provinceCode)) : 0;

        $freeThreshold = DeliveryArea::freeShippingThreshold();
        $freeThresholdCents = $freeThreshold !== null ? Money::toCents($freeThreshold) : null;

        $netSubtotalCents = max(0, $subtotal - $discount);
        $isFreeShipping = false;
        if ($requiresShipping && $freeThresholdCents !== null && $netSubtotalCents >= $freeThresholdCents) {
            $isFreeShipping = true;
            $shipping = 0;
        } else {
            $shipping = $standardShipping;
        }

        $amountNeededForFreeShippingCents = ($requiresShipping && $freeThresholdCents !== null)
            ? max(0, $freeThresholdCents - $netSubtotalCents)
            : null;

        $estimatedDelivery = $requiresShipping
            ? DeliveryArea::estimate(now(), $address?->city, $address?->state)
            : null;

        $hasPreorder = false;
        $hasBackorder = false;
        $preorderDates = [];
        foreach ($summary['lines'] as $line) {
            $variant = $line['item']->variant;
            if ($variant) {
                if ($variant->isPreorder()) {
                    $hasPreorder = true;
                    if ($variant->release_date) {
                        $preorderDates[] = $variant->release_date->format('Y-m-d');
                    }
                } elseif (! $variant->format->isDigital() && ($variant->isBackorder() || $variant->stock_quantity < $line['item']->quantity)) {
                    $hasBackorder = true;
                }
            }
        }
        $latestReleaseDate = $preorderDates !== [] ? max($preorderDates) : null;
        if ($hasBackorder) {
            $estimatedDelivery = null; // No calendar promise until stock has a known arrival date.
        } elseif ($hasPreorder && $latestReleaseDate && $requiresShipping) {
            $estimatedDelivery = DeliveryArea::estimate(now()->parse($latestReleaseDate)->startOfDay(), $address?->city, $address?->state);
        }
        $shippingNotice = null;
        if ($hasPreorder && $latestReleaseDate) {
            $shippingNotice = __('messages.ships_when_ready_from', ['date' => $latestReleaseDate]);
        } elseif ($hasBackorder) {
            $wait = config('shop.backorder_estimated_wait', '2–3 weeks');
            $shippingNotice = __('messages.ships_when_ready_wait', ['wait' => $wait]);
        }

        return [
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'referral_discount_cents' => $referralDiscount,
            'shipping_cents' => $shipping,
            'standard_shipping_cents' => $standardShipping,
            'is_free_shipping' => $isFreeShipping,
            'free_shipping_threshold_cents' => $freeThresholdCents,
            'amount_needed_for_free_shipping_cents' => $amountNeededForFreeShippingCents,
            'tax_cents' => 0,
            'total_cents' => $subtotal - $discount + $shipping,
            'requires_shipping' => $requiresShipping,
            'delivery_area' => $address ? $area : null,
            'province_code' => $provinceCode,
            'province_name' => $province['name_en'] ?? null,
            'estimated_delivery' => $estimatedDelivery,
            'coupon' => $coupon,           // only when the coupon won
            'choice' => $choice,           // applied, offer, coupon not used
            'can_checkout' => $summary['can_checkout'],
            'has_preorder' => $hasPreorder,
            'has_backorder' => $hasBackorder,
            'expected_ship_date' => $latestReleaseDate,
            'shipping_notice' => $shippingNotice,
            'free_gift' => $freeGift,
        ];
    }

    /** "Order placed": staff alert, the customer's email and Telegram update. */
    public static function announce(Order $order): void
    {
        app(OpsAlerts::class)->newOrder($order);
        $order->customer->notify(new OrderPlacedNotification($order));
        app(CustomerOrderUpdates::class)->orderPlaced($order);
    }
}

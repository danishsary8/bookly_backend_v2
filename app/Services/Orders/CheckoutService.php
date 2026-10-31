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
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffUser;
use App\Notifications\LowStockNotification;
use App\Notifications\OrderPlacedNotification;
use App\Services\Cart\CartService;
use App\Services\Cart\CouponService;
use App\Services\Inventory\InventoryService;
use App\Services\Offers\CartPricing;
use App\Services\Offers\DiscountChoice;
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
    ) {}

    /**
     * Totals for the current cart, without placing anything. Delivery is priced for the given address,
     * else the customer's default address, else the provinces fee (never quote less than checkout charges).
     */
    public function quote(Customer $customer, ?string $couponCode = null, ?int $addressId = null): array
    {
        $addresses = CustomerAddress::where('customer_id', $customer->id);
        $address = $addressId !== null
            ? ((clone $addresses)->find($addressId) ?? throw ValidationException::withMessages(['address_id' => __('messages.checkout_choose_address')]))
            : (clone $addresses)->where('is_default', true)->first();

        return $this->totals($customer, $this->carts->summary($customer), $couponCode, $address);
    }

    /**
     * Places a cash-on-delivery order from the customer's cart.
     *
     * @return array{order: Order, created: bool} created=false when the idempotency key was already used
     */
    public function place(Customer $customer, int $addressId, ?string $couponCode, string $idempotencyKey, ?string $deliveryNote = null): array
    {
        $crossedLowStock = [];

        $result = DB::transaction(function () use ($customer, $addressId, $couponCode, $idempotencyKey, $deliveryNote, &$crossedLowStock) {
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
            $totals = $this->totals($customer, $summary, $couponCode, $address);

            // 5. Create the order with a copy of the address and today's prices.
            $order = Order::create([
                'customer_id' => $customer->id,
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
                'coupon_id' => $totals['coupon']?->id,
                'status' => OrderStatus::Pending,
                'subtotal' => Money::format($totals['subtotal_cents']),
                'discount_amount' => Money::format($totals['discount_cents']),
                'shipping_fee' => Money::format($totals['shipping_cents']),
                'tax_amount' => '0.00',
                'total_amount' => Money::format($totals['total_cents']),
                'currency' => 'USD',
                'payment_method' => PaymentMethod::Cod,
                'placed_at' => now(),
                'delivery_note' => $deliveryNote,
            ]);

            foreach ($summary['lines'] as $line) {
                $item = $line['item'];
                $variant = $variants[$item->book_variant_id];

                $order->items()->create([
                    'book_variant_id' => $variant->id,
                    'quantity' => $item->quantity,
                    'unit_price' => $variant->price_usd,
                    'subtotal' => Money::format($line['line_total_cents']),
                    ...OrderItem::detailsOf($variant),
                ]);

                if (! $variant->format->isDigital() && $this->inventory->deductForSale($variant, $item->quantity, $order)) {
                    $crossedLowStock[] = $variant;
                }
            }

            if ($totals['coupon']) {
                $totals['coupon']->increment('used_count');
            }

            $order->statusHistory()->create(['status' => OrderStatus::Pending, 'note' => 'Order placed.']);
            $order->payments()->create([
                'provider' => PaymentProvider::Cod,
                'amount' => $order->total_amount,
                'currency' => 'USD',
                'status' => PaymentStatus::Pending,
            ]);

            $cart->items()->delete();

            return ['order' => $order, 'created' => true];
        });

        if ($result['created']) {
            app(OpsAlerts::class)->newOrder($result['order']);
            $customer->notify(new OrderPlacedNotification($result['order']));
            app(CustomerOrderUpdates::class)->orderPlaced($result['order']);

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

    /**
     * THE totals function: the preview (quote) and placing an order (place) both use it, so they cannot differ.
     * Offer vs coupon is decided by DiscountChoice (better one wins, tie -> offer). A coupon that is wrong, expired or
     * needs a bigger order is still refused exactly as before, even when an offer would win anyway.
     */
    private function totals(Customer $customer, array $summary, ?string $couponCode, ?CustomerAddress $address): array
    {
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

        $choice = DiscountChoice::pick($this->pricing->bestOffer($summary), $coupon, $couponDiscount);
        $discount = $choice->discountCents;
        $coupon = $choice->coupon;

        $requiresShipping = $summary['lines']->contains(fn ($line) => ! $line['item']->variant->format->isDigital());
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

        return [
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
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
        ];
    }
}

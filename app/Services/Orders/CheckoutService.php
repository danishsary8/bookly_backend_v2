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
use App\Models\StaffUser;
use App\Notifications\LowStockNotification;
use App\Notifications\OrderPlacedNotification;
use App\Services\Cart\CartService;
use App\Services\Cart\CouponService;
use App\Services\Inventory\InventoryService;
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
    ) {}

    /** Totals for the current cart, without placing anything. */
    public function quote(Customer $customer, ?string $couponCode = null): array
    {
        return $this->totals($customer, $this->carts->summary($customer), $couponCode);
    }

    /**
     * Places a cash-on-delivery order from the customer's cart.
     *
     * @return array{order: Order, created: bool} created=false when the idempotency key was already used
     */
    public function place(Customer $customer, int $addressId, ?string $couponCode, string $idempotencyKey): array
    {
        $crossedLowStock = [];

        $result = DB::transaction(function () use ($customer, $addressId, $couponCode, $idempotencyKey, &$crossedLowStock) {
            // 1. Lock the cart: concurrent checkouts by the same customer run one after another.
            $cart = $this->carts->cartFor($customer);
            Cart::whereKey($cart->id)->lockForUpdate()->first();

            // 2. Same key again (double click, retry after a timeout): return the order already placed.
            $existing = Order::where('customer_id', $customer->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return ['order' => $existing, 'created' => false];
            }

            $address = CustomerAddress::where('customer_id', $customer->id)->find($addressId)
                ?? throw ValidationException::withMessages(['address_id' => 'Choose one of your saved addresses.']);

            // 3. Lock every format in the cart, always in id order so two checkouts cannot deadlock.
            $variantIds = $cart->items()->orderBy('book_variant_id')->pluck('book_variant_id');
            $variants = BookVariant::whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // 4. Re-check the cart with today's prices and stock now that nothing can change underneath us.
            $summary = $this->carts->summary($customer);
            if ($summary['lines']->isEmpty()) {
                throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
            }
            if (! $summary['can_checkout']) {
                throw ValidationException::withMessages(['cart' => 'Some items in your cart are unavailable or do not have enough stock. Please review your cart.']);
            }

            if ($couponCode !== null && $couponCode !== '') {
                Coupon::where('code', CouponService::normalize($couponCode))->lockForUpdate()->first();
            }
            $totals = $this->totals($customer, $summary, $couponCode);

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
            ]);

            foreach ($summary['lines'] as $line) {
                $item = $line['item'];
                $variant = $variants[$item->book_variant_id];

                $order->items()->create([
                    'book_variant_id' => $variant->id,
                    'quantity' => $item->quantity,
                    'unit_price' => $variant->price_usd,
                    'subtotal' => Money::format($line['line_total_cents']),
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
            $customer->notify(new OrderPlacedNotification($result['order']));

            if ($crossedLowStock !== []) {
                $staff = StaffUser::all();
                foreach ($crossedLowStock as $variant) {
                    Notification::send($staff, LowStockNotification::for($variant->load('book')));
                }
            }
        }

        return $result;
    }

    private function totals(Customer $customer, array $summary, ?string $couponCode): array
    {
        $subtotal = $summary['subtotal_cents'];
        $coupon = null;
        $discount = 0;

        if ($couponCode !== null && $couponCode !== '') {
            if ($subtotal === 0) {
                throw ValidationException::withMessages(['coupon_code' => 'Add items to your cart before using a coupon.']);
            }
            try {
                ['coupon' => $coupon, 'discount_cents' => $discount] = $this->coupons->evaluate($customer, $couponCode, $subtotal);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['coupon_code' => $e->errors()['code'] ?? $e->getMessage()]);
            }
        }

        $requiresShipping = $summary['lines']->contains(fn ($line) => ! $line['item']->variant->format->isDigital());
        $shipping = $requiresShipping ? Money::toCents(config('shop.shipping_flat_fee')) : 0;

        return [
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'shipping_cents' => $shipping,
            'tax_cents' => 0,
            'total_cents' => $subtotal - $discount + $shipping,
            'requires_shipping' => $requiresShipping,
            'coupon' => $coupon,
            'can_checkout' => $summary['can_checkout'],
        ];
    }
}

<?php

namespace Tests\Concerns;

use App\Enums\OrderStatus;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\Order;
use App\Support\Money;

trait BuildsOrders
{
    /**
     * A delivered order built directly with factories. $lines: [[BookVariant, quantity], ...].
     * Prices come from the variants; $discount is the coupon discount on the whole order.
     */
    protected function deliveredOrder(Customer $customer, array $lines, string $discount = '0.00', ?\DateTimeInterface $deliveredAt = null): Order
    {
        $subtotal = collect($lines)->sum(fn ($l) => Money::toCents($l[0]->price_usd) * $l[1]);

        $order = Order::factory()->for($customer)->create([
            'status' => OrderStatus::Delivered,
            'subtotal' => Money::format($subtotal),
            'discount_amount' => $discount,
            'total_amount' => Money::format($subtotal - Money::toCents($discount)),
        ]);

        foreach ($lines as [$variant, $qty]) {
            $order->items()->create([
                'book_variant_id' => $variant->id,
                'quantity' => $qty,
                'unit_price' => $variant->price_usd,
                'subtotal' => Money::format(Money::toCents($variant->price_usd) * $qty),
            ]);
        }

        $order->payments()->create(['provider' => 'cod', 'amount' => $order->total_amount, 'currency' => 'USD', 'status' => 'succeeded']);
        $history = $order->statusHistory()->create(['status' => OrderStatus::Delivered, 'note' => 'Delivered']);
        $history->forceFill(['created_at' => $deliveredAt ?? now()])->save();

        return $order->fresh();
    }

    protected function physical(string $price = '10.00', int $stock = 50): BookVariant
    {
        return BookVariant::factory()->create(['price_usd' => $price, 'stock_quantity' => $stock]);
    }
}

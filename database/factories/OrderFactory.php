<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'order_number' => 'ORD-'.now()->format('Ymd').'-'.strtoupper(fake()->unique()->bothify('#####')),
            'shipping_recipient_name' => fake()->name(),
            'shipping_phone' => '012345678',
            'shipping_address_line1' => fake()->streetAddress(),
            'shipping_city' => 'Phnom Penh',
            'shipping_country' => 'Cambodia',
            'status' => OrderStatus::Pending,
            'subtotal' => '19.99',
            'total_amount' => '19.99',
            'currency' => 'USD',
            'payment_method' => PaymentMethod::Cod,
            'placed_at' => now(),
        ];
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}

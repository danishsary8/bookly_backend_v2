<?php

namespace Database\Factories;

use App\Models\BookVariant;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'book_variant_id' => BookVariant::factory(),
            'quantity' => 1,
            'unit_price' => '19.99',
            'subtotal' => '19.99',
        ];
    }
}

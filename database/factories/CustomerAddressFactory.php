<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerAddressFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'label' => 'Home',
            'recipient_name' => fake()->name(),
            'phone' => fake()->numerify('0#########'),
            'address_line1' => fake()->streetAddress(),
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'is_default' => true,
        ];
    }
}

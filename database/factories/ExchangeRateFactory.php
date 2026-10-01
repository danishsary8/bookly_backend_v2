<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ExchangeRateFactory extends Factory
{
    public function definition(): array
    {
        return ['base_currency' => 'USD', 'target_currency' => 'KHR', 'rate' => '4100', 'effective_at' => now()->subDay()];
    }
}

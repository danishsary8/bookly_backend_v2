<?php

namespace Database\Factories;

use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Offer> */
class OfferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Buy 2, get 10%',
            'applies_to' => Offer::APPLIES_TO_ALL,
            'min_quantity' => 2,
            'percent_off' => '10.00',
            'is_active' => true,
        ];
    }

    public function selected(): static
    {
        return $this->state(['applies_to' => Offer::APPLIES_TO_SELECTED]);
    }

    public function paused(): static
    {
        return $this->state(['is_active' => false]);
    }
}

<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SeriesFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => ucwords(fake()->words(3, true)), 'description' => fake()->sentence()];
    }
}

<?php

namespace Database\Factories;

use App\Models\Publisher;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => ucwords(fake()->words(3, true)),
            'description' => fake()->paragraph(),
            'publisher_id' => Publisher::factory(),
            'language' => 'English',
            'page_count' => fake()->numberBetween(80, 900),
            'publish_date' => fake()->date(),
        ];
    }
}

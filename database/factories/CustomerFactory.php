<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => 'Password123!',
            'phone' => fake()->numerify('0#########'),
            'email_verified_at' => now(),
        ];
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}

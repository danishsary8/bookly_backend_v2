<?php

namespace Database\Factories;

use App\Enums\StaffRole;
use Illuminate\Database\Eloquent\Factories\Factory;

class StaffUserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => 'Password123!',
            'role' => StaffRole::Staff,
            'two_factor_enabled' => false,
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => StaffRole::Admin]);
    }

    public function withTwoFactor(string $secret = 'JBSWY3DPEHPK3PXP'): static
    {
        return $this->state(['two_factor_secret' => $secret, 'two_factor_enabled' => true]);
    }
}

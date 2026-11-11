<?php

namespace Database\Factories;

use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Promotion> */
class PromotionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title_en' => 'Rainy season sale',
            'body_en' => 'Save on full sets this week.',
            'cta_url' => '/books',
            'is_active' => true,
        ];
    }
}

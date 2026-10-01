<?php

namespace Database\Factories;

use App\Enums\BookFormat;
use App\Models\Book;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookVariantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'format' => BookFormat::Paperback,
            'isbn' => fake()->unique()->isbn13(),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####-????')),
            'price_usd' => '19.99',
            'stock_quantity' => 50,
            'low_stock_threshold' => 5,
        ];
    }

    public function format(BookFormat $format): static
    {
        return $this->state(['format' => $format]);
    }
}

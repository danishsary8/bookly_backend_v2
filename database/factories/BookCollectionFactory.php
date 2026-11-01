<?php

namespace Database\Factories;

use App\Models\BookCollection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BookCollection> */
class BookCollectionFactory extends Factory
{
    protected $model = BookCollection::class;

    public function definition(): array
    {
        $title = ucwords(fake()->unique()->words(3, true));

        return ['slug' => Str::slug($title), 'title_en' => $title, 'is_active' => true];
    }
}

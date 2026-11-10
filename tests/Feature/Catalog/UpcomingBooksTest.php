<?php

namespace Tests\Feature\Catalog;

use App\Models\Book;
use App\Models\BookVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpcomingBooksTest extends TestCase
{
    use RefreshDatabase;

    private function book(array $attrs = [], array $variant = []): Book
    {
        $book = Book::factory()->create($attrs);
        BookVariant::factory()->for($book)->create($variant);

        return $book;
    }

    public function test_lists_pre_orders_and_scheduled_books_soonest_first(): void
    {
        $preorder = $this->book(['title' => 'Pre-order'], ['release_date' => today()->addDays(20)]);
        $announced = $this->book(['title' => 'Announced', 'status' => 'scheduled', 'publish_at' => now()->addDays(5)]);
        $this->book(['title' => 'Already out'], ['release_date' => today()->subDay()]);
        $this->book(['title' => 'Draft', 'status' => 'draft'], ['release_date' => today()->addDays(3)]);
        $this->book(['title' => 'Inactive format', 'status' => 'scheduled', 'publish_at' => now()->addDays(2)], ['is_active' => false]);

        $this->getJson('/api/v1/catalog/upcoming')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $announced->id)
            ->assertJsonPath('data.0.announced', true)
            ->assertJsonPath('data.0.coming_on', today()->addDays(5)->toDateString())
            ->assertJsonPath('data.1.id', $preorder->id)
            ->assertJsonPath('data.1.announced', false)
            ->assertJsonPath('data.1.coming_on', today()->addDays(20)->toDateString())
            ->assertJsonStructure(['data' => [['id', 'title', 'authors', 'cover_image_url', 'price_from_usd', 'coming_on', 'announced']]]);
    }

    public function test_is_empty_when_nothing_is_coming(): void
    {
        $this->book(['title' => 'Out now']);

        $this->getJson('/api/v1/catalog/upcoming')->assertOk()->assertJsonCount(0, 'data');
    }
}

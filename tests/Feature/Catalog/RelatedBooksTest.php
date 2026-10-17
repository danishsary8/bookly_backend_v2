<?php

namespace Tests\Feature\Catalog;

use App\Enums\BookFormat;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RelatedBooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_related_endpoint_returns_404_for_draft_or_missing_books(): void
    {
        $draft = Book::factory()->create(['title' => 'Draft Book', 'status' => 'draft']);

        $this->getJson('/api/v1/books/99999/related')->assertNotFound();
        $this->getJson("/api/v1/books/{$draft->id}/related")->assertNotFound();
    }

    public function test_related_endpoint_returns_books_by_same_category_and_author(): void
    {
        $category = Category::factory()->create(['name' => 'History']);
        $author = Author::factory()->create(['name' => 'Yuval Noah Harari']);

        $target = Book::factory()->create(['title' => 'Sapiens', 'status' => 'live']);
        $target->categories()->attach($category->id);
        $target->authors()->attach($author->id);
        BookVariant::factory()->create(['book_id' => $target->id, 'format' => BookFormat::Hardcover, 'is_active' => true, 'stock_quantity' => 5]);

        $book1 = Book::factory()->create(['title' => 'Homo Deus', 'status' => 'live']);
        $book1->categories()->attach($category->id);
        $book1->authors()->attach($author->id);
        BookVariant::factory()->create(['book_id' => $book1->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        $book2 = Book::factory()->create(['title' => '21 Lessons for the 21st Century', 'status' => 'live']);
        $book2->categories()->attach($category->id);
        BookVariant::factory()->create(['book_id' => $book2->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        $res = $this->getJson("/api/v1/books/{$target->id}/related?limit=4")->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($book1->id, $ids);
        $this->assertContains($book2->id, $ids);
        $this->assertNotContains($target->id, $ids);
    }

    public function test_related_endpoint_respects_custom_limit(): void
    {
        $category = Category::factory()->create(['name' => 'Science']);
        $target = Book::factory()->create(['title' => 'Cosmos', 'status' => 'live']);
        $target->categories()->attach($category->id);
        BookVariant::factory()->create(['book_id' => $target->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 5]);

        for ($i = 1; $i <= 5; $i++) {
            $b = Book::factory()->create(['title' => "Science Book {$i}", 'status' => 'live']);
            $b->categories()->attach($category->id);
            BookVariant::factory()->create(['book_id' => $b->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);
        }

        $res = $this->getJson("/api/v1/books/{$target->id}/related?limit=2")->assertOk();
        $this->assertCount(2, $res->json('data'));
    }

    public function test_catalog_recommendations_for_cart_suggests_items_from_matching_categories(): void
    {
        $catA = Category::factory()->create(['name' => 'Philosophy']);
        $catB = Category::factory()->create(['name' => 'Economics']);

        // In Cart: Book A (Philosophy)
        $cartBook = Book::factory()->create(['title' => 'Meditations', 'status' => 'live']);
        $cartBook->categories()->attach($catA->id);
        BookVariant::factory()->create(['book_id' => $cartBook->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 5]);

        // Suggestion Candidate 1 (Philosophy)
        $cand1 = Book::factory()->create(['title' => 'Letters from a Stoic', 'status' => 'live']);
        $cand1->categories()->attach($catA->id);
        BookVariant::factory()->create(['book_id' => $cand1->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        // Non-candidate (Economics)
        $cand2 = Book::factory()->create(['title' => 'Freakonomics', 'status' => 'live']);
        $cand2->categories()->attach($catB->id);
        BookVariant::factory()->create(['book_id' => $cand2->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        $res = $this->getJson("/api/v1/catalog/recommendations?cart_ids={$cartBook->id}")
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($cand1->id, $ids);
        $this->assertNotContains($cartBook->id, $ids, 'Cart items must be excluded');
    }

    public function test_catalog_recommendations_falls_back_to_popular_when_cart_is_empty(): void
    {
        $book = Book::factory()->create(['title' => 'Popular Classic', 'status' => 'live']);
        BookVariant::factory()->create(['book_id' => $book->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        $res = $this->getJson('/api/v1/catalog/recommendations')->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($book->id, $ids);
    }
}

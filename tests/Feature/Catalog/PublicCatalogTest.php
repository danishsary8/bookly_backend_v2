<?php

namespace Tests\Feature\Catalog;

use App\Enums\BookFormat;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Review;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function book(array $attrs = [], array $variant = []): Book
    {
        $book = Book::factory()->create($attrs);
        BookVariant::factory()->for($book)->create($variant);

        return $book;
    }

    public function test_lists_only_books_with_an_active_variant(): void
    {
        $visible = $this->book(['title' => 'Visible Book']);
        Book::factory()->create(['title' => 'No Variants Yet']);
        $this->book(['title' => 'Only Inactive'], ['is_active' => false]);
        $this->book(['title' => 'Deleted'])->delete();

        $this->getJson('/api/v1/books')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id)
            ->assertJsonStructure(['data' => [['id', 'title', 'authors', 'price_from_usd', 'price_from_khr', 'formats', 'in_stock', 'rating_avg', 'review_count']], 'links', 'meta']);
    }

    public function test_full_text_search_ranks_matches(): void
    {
        $this->book(['title' => 'The Hobbit', 'description' => 'Dwarves and a dragon']);
        $this->book(['title' => 'Cooking Basics', 'description' => 'Recipes for beginners']);

        $this->getJson('/api/v1/books?q=dragon')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'The Hobbit');
        $this->getJson('/api/v1/books?q=zzzz')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_filters(): void
    {
        $category = Category::factory()->create();
        $author = Author::factory()->create();
        $series = Series::factory()->create();

        $a = $this->book(['language' => 'Khmer', 'series_id' => $series->id], ['price_usd' => '5.00', 'stock_quantity' => 0]);
        $a->categories()->attach($category);
        $a->authors()->attach($author);
        $b = $this->book(['language' => 'English'], ['price_usd' => '30.00', 'format' => BookFormat::Ebook, 'stock_quantity' => 0]);

        $ids = fn (string $qs) => collect($this->getJson('/api/v1/books?'.$qs)->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$a->id], $ids("category_id={$category->id}"));
        $this->assertSame([$a->id], $ids("author_id={$author->id}"));
        $this->assertSame([$a->id], $ids("series_id={$series->id}"));
        $this->assertSame([$a->id], $ids('language=khmer'));
        $this->assertSame([$b->id], $ids('format=ebook'));
        $this->assertSame([$b->id], $ids('min_price=10'));
        $this->assertSame([$a->id], $ids('max_price=10'));
        $this->assertSame([$b->id], $ids('in_stock=1'), 'paperback with 0 stock is out, ebook is always in stock');
    }

    public function test_sorting_by_price_title_and_rating(): void
    {
        $cheap = $this->book(['title' => 'B Cheap'], ['price_usd' => '5.00']);
        $pricey = $this->book(['title' => 'A Pricey'], ['price_usd' => '50.00']);
        Review::create(['book_id' => $pricey->id, 'customer_id' => \App\Models\Customer::factory()->create()->id,
            'order_item_id' => \App\Models\OrderItem::factory()->create()->id, 'rating' => 5]);

        $ids = fn (string $sort) => collect($this->getJson('/api/v1/books?sort='.$sort)->json('data'))->pluck('id')->all();

        $this->assertSame([$cheap->id, $pricey->id], array_values(array_intersect($ids('price_asc'), [$cheap->id, $pricey->id])));
        $this->assertSame([$pricey->id, $cheap->id], array_values(array_intersect($ids('price_desc'), [$cheap->id, $pricey->id])));
        $this->assertSame([$pricey->id, $cheap->id], array_values(array_intersect($ids('title'), [$cheap->id, $pricey->id])));
        $this->assertSame($pricey->id, $ids('rating')[0]);
    }

    public function test_pagination_defaults_to_20_and_caps_at_100(): void
    {
        foreach (range(1, 21) as $i) {
            $this->book();
        }

        $this->getJson('/api/v1/books')->assertJsonCount(20, 'data')->assertJsonPath('meta.per_page', 20);
        $this->getJson('/api/v1/books?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/books?sort=bogus')->assertUnprocessable();
        $this->getJson('/api/v1/books?min_price=20&max_price=10')->assertUnprocessable();
    }

    public function test_listing_query_count_does_not_grow_with_number_of_books(): void
    {
        ExchangeRate::factory()->create();
        $count = function (): int {
            $this->app->forgetScopedInstances();
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            $this->getJson('/api/v1/books')->assertOk();

            return count(\DB::getQueryLog());
        };

        $this->book()->authors()->attach(Author::factory()->create());
        $few = $count();
        foreach (range(1, 15) as $i) {
            $this->book()->authors()->attach(Author::factory()->create());
        }

        $this->assertSame($few, $count());
    }

    public function test_price_khr_uses_latest_rate_or_is_null(): void
    {
        $book = $this->book([], ['price_usd' => '10.00']);

        $this->getJson("/api/v1/books/{$book->id}")->assertJsonPath('data.price_from_khr', null);

        ExchangeRate::factory()->create(['rate' => '4000', 'effective_at' => now()->subDays(2)]);
        ExchangeRate::factory()->create(['rate' => '4100', 'effective_at' => now()->subDay()]);
        ExchangeRate::factory()->create(['rate' => '9999', 'effective_at' => now()->addDay()]); // future, ignored
        $this->app->forgetScopedInstances();

        $this->getJson("/api/v1/books/{$book->id}")
            ->assertJsonPath('data.price_from_usd', '10.00')
            ->assertJsonPath('data.price_from_khr', '41000')
            ->assertJsonPath('data.variants.0.price_khr', '41000');
    }

    public function test_book_detail_shows_active_variants_and_relations(): void
    {
        $book = $this->book(['series_order' => 2, 'series_id' => Series::factory()->create()->id]);
        BookVariant::factory()->for($book)->format(BookFormat::Audiobook)->create(['is_active' => false]);
        $book->categories()->attach(Category::factory()->create());

        $this->getJson("/api/v1/books/{$book->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.variants')
            ->assertJsonPath('data.series.order', 2)
            ->assertJsonStructure(['data' => ['description', 'page_count', 'categories', 'publisher', 'series', 'variants' => [['id', 'format', 'price_usd', 'price_khr', 'in_stock']]]])
            ->assertJsonMissingPath('data.variants.0.stock_quantity');
    }

    public function test_hidden_books_return_404(): void
    {
        $noVariant = Book::factory()->create();
        $deleted = $this->book();
        $deleted->delete();

        $this->getJson("/api/v1/books/{$noVariant->id}")->assertNotFound();
        $this->getJson("/api/v1/books/{$deleted->id}")->assertNotFound();
    }

    public function test_authors_categories_publishers_series_lists(): void
    {
        $author = Author::factory()->create(['name' => 'Ursula Le Guin']);
        Author::factory()->create(['name' => 'Someone Else']);
        $book = $this->book();
        $book->authors()->attach($author);
        $series = Series::factory()->create();
        $book->update(['series_id' => $series->id, 'series_order' => 1]);

        $this->getJson('/api/v1/authors?q=guin')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.books_count', 1);
        $this->getJson("/api/v1/authors/{$author->id}")->assertOk()->assertJsonPath('data.name', 'Ursula Le Guin');
        $this->getJson('/api/v1/categories')->assertOk();
        $this->getJson('/api/v1/publishers')->assertOk()->assertJsonPath('meta.per_page', 20);
        $this->getJson('/api/v1/series')->assertOk();
        $this->getJson("/api/v1/series/{$series->id}")->assertOk()->assertJsonPath('data.books.0.id', $book->id);
    }
}

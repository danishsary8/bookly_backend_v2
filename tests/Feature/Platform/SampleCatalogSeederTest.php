<?php

namespace Tests\Feature\Platform;

use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use Database\Seeders\SampleCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SampleCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_catalog_creates_at_least_120_books_across_top_level_categories_and_is_idempotent(): void
    {
        $this->seed(SampleCatalogSeeder::class);

        // 1. At least 120 visible (live) books created
        $liveCount = Book::where('status', 'live')->count();
        $this->assertGreaterThanOrEqual(120, $liveCount);

        // 2. Across >= 10 top-level categories
        $topLevelWithBooks = Category::whereNull('parent_id')
            ->where(function ($query) {
                $query->whereHas('books')
                    ->orWhereHas('children', fn ($childQuery) => $childQuery->whereHas('books'));
            })
            ->count();
        $this->assertGreaterThanOrEqual(10, $topLevelWithBooks);

        // 3. Every book has an active format with stock
        $books = Book::with('variants')->get();
        foreach ($books as $book) {
            $activeInStock = $book->variants->where('is_active', true)->where('stock_quantity', '>', 0);
            $this->assertTrue($activeInStock->isNotEmpty(), "Book '{$book->title}' has no active format with stock.");
        }

        // 4. Running it twice adds nothing (idempotent)
        $bookCountBefore = Book::count();
        $variantCountBefore = BookVariant::count();

        $this->seed(SampleCatalogSeeder::class);

        $this->assertSame($bookCountBefore, Book::count());
        $this->assertSame($variantCountBefore, BookVariant::count());
    }

    public function test_production_refuses_unless_explicitly_allowed(): void
    {
        $this->app['env'] = 'production';

        $seed = fn () => $this->artisan('db:seed', ['--class' => SampleCatalogSeeder::class, '--force' => true]);

        $seed()->expectsOutputToContain('does not run in production')->assertSuccessful();
        $this->assertSame(0, Book::count(), 'Refused without DEMO_SEED_ALLOWED');

        config(['app.demo_seed_allowed' => true]);
        $seed()->assertSuccessful();
        $this->assertGreaterThanOrEqual(120, Book::count());
    }
}

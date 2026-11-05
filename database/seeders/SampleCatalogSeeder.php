<?php

namespace Database\Seeders;

use App\Enums\BookFormat;
use App\Models\Author;
use App\Models\Book;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\Series;
use App\Support\StarterCategories;
use Illuminate\Database\Seeder;

/**
 * Realistic sample catalogue (120+ books) for local development and demos.
 *
 *   php artisan db:seed --class=SampleCatalogSeeder
 *
 * Idempotent: skips any book where the title and primary author already exist.
 * Refuses to run in production without DEMO_SEED_ALLOWED=true.
 */
class SampleCatalogSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction() && ! config('app.demo_seed_allowed')) {
            $this->command?->error('SampleCatalogSeeder does not run in production. To fill a public demo site once, set DEMO_SEED_ALLOWED=true, run it, then remove the variable.');

            return;
        }

        // Install the full starter tree first so sub-categories are ready.
        StarterCategories::install();

        /** @var list<array<string, mixed>> $booksData */
        $booksData = require __DIR__.'/data/sample_books.php';
        $categories = Category::all()->keyBy('slug');

        $createdCount = 0;
        $counter = Book::withTrashed()->count();

        foreach ($booksData as $item) {
            $firstAuthorName = is_array($item['authors']) ? $item['authors'][0] : $item['authors'];
            $author = Author::firstOrCreate(['name' => $firstAuthorName]);

            // Idempotency: skip a book when the same title + first author exists
            $exists = Book::where('title', $item['title'])
                ->whereHas('authors', fn ($q) => $q->where('authors.id', $author->id))
                ->exists();

            if ($exists) {
                continue;
            }

            $counter++;

            $publisher = Publisher::firstOrCreate(['name' => $item['publisher']]);
            $series = ! empty($item['series']) ? Series::firstOrCreate(['name' => $item['series']]) : null;

            $book = Book::create([
                'title' => $item['title'],
                'description' => $item['description'],
                'status' => 'live',
                'publisher_id' => $publisher->id,
                'series_id' => $series?->id,
                'series_order' => $item['series_order'] ?? null,
                'language' => $item['language'] ?? 'English',
                'page_count' => $item['page_count'] ?? null,
                'publish_date' => $item['publish_date'] ?? null,
                'age_min' => $item['age_min'] ?? null,
                'age_max' => $item['age_max'] ?? null,
                'school_grade' => $item['school_grade'] ?? null,
                'level' => $item['level'] ?? null,
            ]);

            // Sync authors
            $authorIds = [$author->id];
            if (is_array($item['authors']) && count($item['authors']) > 1) {
                for ($a = 1; $a < count($item['authors']); $a++) {
                    $otherAuthor = Author::firstOrCreate(['name' => $item['authors'][$a]]);
                    $authorIds[] = $otherAuthor->id;
                }
            }
            $book->authors()->sync($authorIds);

            // Attach sub-category
            $category = $categories->get($item['category_slug']);
            if ($category) {
                $book->categories()->sync([$category->id]);
            }

            // 1. Paperback variant
            if (! empty($item['paperback_price'])) {
                $book->variants()->create([
                    'format' => BookFormat::Paperback,
                    'sku' => sprintf('SMP-%04d-PB', $counter),
                    'isbn' => $this->isbn($counter, 1),
                    'price_usd' => $item['paperback_price'],
                    'stock_quantity' => 20 + ($counter % 35), // 20–54 stock
                    'low_stock_threshold' => 5,
                    'is_active' => true,
                    'cover_image_url' => null,
                ]);
            }

            // 2. Hardcover variant
            if (! empty($item['hardcover_price'])) {
                $book->variants()->create([
                    'format' => BookFormat::Hardcover,
                    'sku' => sprintf('SMP-%04d-HC', $counter),
                    'isbn' => $this->isbn($counter, 2),
                    'price_usd' => $item['hardcover_price'],
                    'stock_quantity' => 15 + ($counter % 25), // 15–39 stock
                    'low_stock_threshold' => 5,
                    'is_active' => true,
                    'cover_image_url' => null,
                ]);
            }

            // 3. Ebook variant
            if (! empty($item['has_ebook'])) {
                $book->variants()->create([
                    'format' => BookFormat::Ebook,
                    'sku' => sprintf('SMP-%04d-EB', $counter),
                    'isbn' => $this->isbn($counter, 3),
                    'price_usd' => $item['ebook_price'] ?? 6.99,
                    'stock_quantity' => 0,
                    'low_stock_threshold' => 0,
                    'is_active' => true,
                    'cover_image_url' => null,
                ]);
            }

            $createdCount++;
        }

        $this->command?->info("SampleCatalogSeeder finished: created {$createdCount} books.");
    }

    private function isbn(int $bookIndex, int $formatIndex): string
    {
        $body = sprintf('9789995%05d', ($bookIndex * 10 + $formatIndex) % 100000);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $body[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        $check = (10 - ($sum % 10)) % 10;

        return $body.$check;
    }
}

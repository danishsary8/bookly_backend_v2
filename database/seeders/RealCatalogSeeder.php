<?php

namespace Database\Seeders;

use App\Enums\BookFormat;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\Series;
use App\Support\StarterCategories;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Real catalog seeder for local development and demos.
 * Loads verified books from data/catalog/books.csv and attaches covers from storage/app/catalog-covers.
 *
 *   php artisan db:seed --class=RealCatalogSeeder
 *
 * Idempotent: skips any book where ISBN already exists, or where title and first author exist if no ISBN.
 * Refuses to run in production without DEMO_SEED_ALLOWED=true.
 */
class RealCatalogSeeder extends Seeder
{
    private const ISBN_SQL = "upper(regexp_replace(coalesce(isbn, ''), '[^0-9Xx]', '', 'g')) = ?";

    public function run(): void
    {
        if (app()->isProduction() && ! config('app.demo_seed_allowed')) {
            $this->command?->error('RealCatalogSeeder does not run in production. To fill a public demo site once, set DEMO_SEED_ALLOWED=true, run it, then remove the variable.');

            return;
        }

        // Ensure category tree is installed first
        StarterCategories::install();

        $csvPath = config('catalog.csv_path') ?: base_path('data/catalog/books.csv');
        if (! File::exists($csvPath)) {
            $this->command?->error("Catalog CSV not found at {$csvPath}. Run 'php artisan catalog:build' first.");

            return;
        }

        $handle = fopen($csvPath, 'r');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle, null, ',', '"', '');
        if ($header === false) {
            fclose($handle);
            $this->command?->error('Empty catalog CSV.');

            return;
        }

        $columns = array_map(fn ($c) => strtolower(trim((string) $c)), $header);
        $categoriesByName = Category::all()->groupBy(fn (Category $c) => mb_strtolower($c->name));
        $mediaDisk = (string) config('filesystems.media_disk', 'public');
        $disk = Storage::disk($mediaDisk);
        $coversDir = storage_path('app/catalog-covers');

        $booksCreated = 0;
        $variantsCreated = 0;
        $skipped = 0;
        $activeBooks = [];

        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue;
            }

            $row = [];
            foreach ($columns as $i => $column) {
                $row[$column] = trim((string) ($cells[$i] ?? ''));
            }

            $title = $row['title'] ?? '';
            $rawAuthors = $row['authors'] ?? '';
            $authorList = array_filter(array_map('trim', explode(';', $rawAuthors)));
            $firstAuthor = $authorList[0] ?? '';
            $isbn = ! empty($row['isbn']) ? strtoupper(preg_replace('/[^0-9X]/i', '', $row['isbn'])) : null;
            $sku = $row['sku'] ?? '';
            $formatStr = strtolower($row['format'] ?? 'paperback');

            // Strictly prevent ebook format creation
            if ($formatStr === 'ebook') {
                continue;
            }

            $format = $formatStr === 'hardcover' ? BookFormat::Hardcover : BookFormat::Paperback;

            // Check idempotency: by SKU, then by ISBN if present, else by title + first author + format
            $exists = false;
            if ($sku !== '') {
                $exists = BookVariant::whereRaw('lower(sku) = ?', [mb_strtolower($sku)])->exists();
            }
            if (! $exists && $isbn !== null) {
                $exists = BookVariant::whereRaw(self::ISBN_SQL, [$isbn])->exists();
            }
            if (! $exists && $isbn === null && $firstAuthor !== '') {
                $exists = Book::where('title', $title)
                    ->whereHas('authors', fn ($q) => $q->whereRaw('lower(name) = ?', [mb_strtolower($firstAuthor)]))
                    ->whereHas('variants', fn ($q) => $q->where('format', $format->value))
                    ->exists();
            }

            if ($exists) {
                $skipped++;

                continue;
            }

            // Find or create book model
            $bookGroupKey = mb_strtolower($title).'|'.mb_strtolower($rawAuthors);
            $book = $activeBooks[$bookGroupKey] ?? null;

            if ($book === null) {
                $publisherId = null;
                if (! empty($row['publisher'])) {
                    $publisher = Publisher::firstOrCreate(['name' => $row['publisher']]);
                    $publisherId = $publisher->id;
                }

                $seriesId = null;
                if (! empty($row['series'])) {
                    $series = Series::whereRaw('lower(name) = ?', [mb_strtolower($row['series'])])->first()
                        ?? Series::create(['name' => $row['series']]);
                    $seriesId = $series->id;
                }

                $book = Book::create([
                    'title' => $title,
                    'description' => ! empty($row['description']) ? $row['description'] : null,
                    'status' => in_array($row['status'] ?? '', ['draft', 'scheduled', 'live'], true) ? $row['status'] : 'live',
                    'publisher_id' => $publisherId,
                    'series_id' => $seriesId,
                    'series_order' => ! empty($row['series_order']) ? (int) $row['series_order'] : null,
                    'language' => ! empty($row['language']) ? $row['language'] : 'English',
                    'page_count' => ! empty($row['page_count']) ? (int) $row['page_count'] : null,
                    'publish_date' => ! empty($row['publish_date']) ? $row['publish_date'] : null,
                    'age_min' => isset($row['age_min']) && is_numeric($row['age_min']) ? (int) $row['age_min'] : null,
                    'age_max' => isset($row['age_max']) && is_numeric($row['age_max']) ? (int) $row['age_max'] : null,
                    'school_grade' => isset($row['school_grade']) && is_numeric($row['school_grade']) ? (int) $row['school_grade'] : null,
                    'level' => ! empty($row['level']) && in_array(strtoupper($row['level']), Book::LEVELS, true) ? strtoupper($row['level']) : null,
                ]);

                // Sync authors
                if ($authorList !== []) {
                    $authorIds = [];
                    foreach ($authorList as $authorName) {
                        $auth = Author::whereRaw('lower(name) = ?', [mb_strtolower($authorName)])->first()
                            ?? Author::create(['name' => $authorName]);
                        $authorIds[] = $auth->id;
                    }
                    $book->authors()->sync(array_values(array_unique($authorIds)));
                }

                // Sync categories
                if (! empty($row['categories'])) {
                    $catIds = [];
                    foreach (array_filter(array_map('trim', explode(';', $row['categories']))) as $catName) {
                        $matched = $categoriesByName->get(mb_strtolower($catName));
                        if ($matched && $matched->isNotEmpty()) {
                            $catIds[] = $matched->first()->id;
                        }
                    }
                    if ($catIds !== []) {
                        $book->categories()->sync(array_values(array_unique($catIds)));
                    }
                }

                $activeBooks[$bookGroupKey] = $book;
                $booksCreated++;
            }

            // Cover attachment from local storage/app/catalog-covers
            $coverImageUrl = null;
            if ($isbn !== null) {
                $localCoverPath = "{$coversDir}/{$isbn}.jpg";
                if (File::exists($localCoverPath)) {
                    $targetPath = "covers/{$isbn}.jpg";
                    try {
                        if (! $disk->exists($targetPath)) {
                            $stream = fopen($localCoverPath, 'r');
                            $disk->put($targetPath, $stream);
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                        }
                        $coverImageUrl = $disk->url($targetPath);
                    } catch (\Throwable) {
                        $coverImageUrl = null;
                    }
                }
            }

            $priceUsd = ! empty($row['price_usd']) ? (float) $row['price_usd'] : 12.00;
            $stockQty = isset($row['stock_quantity']) && is_numeric($row['stock_quantity'])
                ? (int) $row['stock_quantity']
                : rand(15, 40);

            $book->variants()->create([
                'format' => $format,
                'sku' => $sku ?: 'BK-'.uniqid(),
                'isbn' => $isbn,
                'price_usd' => $priceUsd,
                'stock_quantity' => $stockQty,
                'low_stock_threshold' => ! empty($row['low_stock_threshold']) ? (int) $row['low_stock_threshold'] : 5,
                'is_active' => ! in_array(strtolower($row['is_active'] ?? '1'), ['0', 'false', 'no'], true),
                'cover_image_url' => $coverImageUrl ?: (! empty($row['cover_image_url']) ? $row['cover_image_url'] : null),
            ]);

            $variantsCreated++;
        }

        fclose($handle);

        $this->command?->info("RealCatalogSeeder finished: created {$booksCreated} books and {$variantsCreated} variants ({$skipped} skipped).");
    }
}

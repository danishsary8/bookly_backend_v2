<?php

namespace Tests\Feature\Catalog;

use App\Models\Book;
use App\Models\BookVariant;
use Database\Seeders\RealCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RealCatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $tempCsvPath;

    private string $tempCoversDir;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->tempCsvPath = storage_path('app/fixture_test_books.csv');
        $this->tempCoversDir = storage_path('app/catalog-covers');

        File::ensureDirectoryExists(storage_path('app'));
        File::ensureDirectoryExists($this->tempCoversDir);

        Storage::fake('public');
        config([
            'filesystems.media_disk' => 'public',
            'catalog.csv_path' => $this->tempCsvPath,
        ]);
    }

    public function test_production_refuses_unless_explicitly_allowed(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.demo_seed_allowed' => false]);

        $this->artisan('db:seed', ['--class' => RealCatalogSeeder::class, '--force' => true])
            ->expectsOutputToContain('RealCatalogSeeder does not run in production')
            ->assertSuccessful();

        $this->assertSame(0, Book::count());
    }

    public function test_seeder_imports_books_is_idempotent_and_creates_no_ebooks(): void
    {
        // 1. Create a synthetic test cover image in storage/app/catalog-covers/9780141439518.jpg
        $im = imagecreatetruecolor(320, 500);
        ob_start();
        imagejpeg($im, null, 80);
        $jpg = ob_get_clean();
        imagedestroy($im);
        File::put("{$this->tempCoversDir}/9780141439518.jpg", $jpg);

        // 2. Create small fixture CSV
        $headers = [
            'book_id', 'title', 'authors', 'categories', 'series', 'series_order',
            'publisher', 'language', 'page_count', 'publish_date', 'description',
            'status', 'publish_at', 'age_min', 'age_max', 'school_grade', 'level',
            'format', 'sku', 'isbn', 'price_usd', 'stock_quantity',
            'low_stock_threshold', 'is_active', 'cover_image_url', 'notes',
        ];

        $rows = [
            [
                '', 'Pride and Prejudice', 'Jane Austen', 'Classics', '', '',
                'Penguin Classics', 'English', '432', '1813-01-28', 'Classic novel of manners.',
                'live', '', '', '', '', '',
                'paperback', 'TEST-PB-001', '9780141439518', '9.99', '25',
                '5', '1', '', '',
            ],
            [
                '', 'Pride and Prejudice', 'Jane Austen', 'Classics', '', '',
                'Penguin Classics', 'English', '432', '1813-01-28', 'Classic novel of manners.',
                'live', '', '', '', '', '',
                'hardcover', 'TEST-HC-001', '9780141040349', '16.99', '15',
                '5', '1', '', '',
            ],
            [
                '', 'Khmer Reader 7', 'MoEYS', 'Grade 7', '', '',
                'MoEYS Cambodia', 'Khmer', '150', '2022-01-01', 'Official grade 7 reader.',
                'live', '', '', '', '7', '',
                'paperback', 'TEST-KM-001', '', '3.50', '30',
                '5', '1', '', 'https://moeys.gov.kh',
            ],
        ];

        $handle = fopen($this->tempCsvPath, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers);
        foreach ($rows as $r) {
            fputcsv($handle, $r);
        }
        fclose($handle);

        // Run seeder
        $this->seed(RealCatalogSeeder::class);

        // Check books created
        $this->assertSame(2, Book::count());
        $this->assertSame(3, BookVariant::count());

        // Strictly verify NO ebook format is created
        $this->assertSame(0, BookVariant::where('format', 'ebook')->count());

        // Check cover was copied to media disk for 9780141439518
        $variant = BookVariant::where('isbn', '9780141439518')->first();
        $this->assertNotNull($variant);
        $this->assertNotNull($variant->cover_image_url);
        Storage::disk('public')->assertExists('covers/9780141439518.jpg');

        // Check Khmer book without ISBN was created with correct metadata
        $khmerBook = Book::where('title', 'Khmer Reader 7')->first();
        $this->assertNotNull($khmerBook);
        $this->assertSame(7, $khmerBook->school_grade);
        $this->assertSame('Khmer', $khmerBook->language);

        // Idempotency: run seeder again, counts must not change
        $this->seed(RealCatalogSeeder::class);
        $this->assertSame(2, Book::count());
        $this->assertSame(3, BookVariant::count());

        // Clean up test fixture
        File::delete($this->tempCsvPath);
        File::delete("{$this->tempCoversDir}/9780141439518.jpg");
    }
}

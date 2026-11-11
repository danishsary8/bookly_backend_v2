<?php

namespace Tests\Feature\Catalog;

use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\StaffUser;
use App\Services\Catalog\BookCsvImport;
use App\Support\StarterCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CatalogCsvImportCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        StarterCategories::install();
    }

    public function test_catalog_csv_format_matches_real_book_csv_importer(): void
    {
        $admin = StaffUser::factory()->admin()->withTwoFactor()->create();

        $headers = [
            'book_id', 'title', 'authors', 'categories', 'series', 'series_order',
            'publisher', 'language', 'page_count', 'publish_date', 'description',
            'status', 'publish_at', 'age_min', 'age_max', 'school_grade', 'level',
            'format', 'sku', 'isbn', 'price_usd', 'stock_quantity',
            'low_stock_threshold', 'is_active', 'cover_image_url', 'notes',
        ];

        $fixturePath = storage_path('app/fixture_import_test.csv');
        $handle = fopen($fixturePath, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers);

        fputcsv($handle, [
            '', 'Pride and Prejudice', 'Jane Austen', 'Classics', '', '',
            'Penguin Classics', 'English', '432', '1813-01-28', 'Classic novel of manners.',
            'live', '', '', '', '', '',
            'paperback', 'TEST-IMP-PB-01', '9780141439518', '9.99', '25',
            '5', '1', 'https://example.com/cover.jpg', 'https://openlibrary.org',
        ]);
        fputcsv($handle, [
            '', 'English Grammar in Use', 'Raymond Murphy', 'English books (Oxford, Cambridge…)', '', '',
            'Cambridge University Press', 'English', '380', '2019-01-24', 'Self-study grammar reference.',
            'live', '', '', '', '', 'B1',
            'paperback', 'TEST-IMP-PB-02', '9781108457651', '24.50', '20',
            '5', '1', '', '',
        ]);
        fclose($handle);

        $importer = app(BookCsvImport::class);
        $records = $importer->read($fixturePath);

        $this->assertCount(2, $records);
        $this->assertArrayHasKey('sku', $records[2]);
        $this->assertArrayHasKey('title', $records[2]);

        $results = $importer->analyse($records);
        $this->assertCount(2, $results);

        foreach ($results as $res) {
            $this->assertSame('new', $res['action'], 'Row failed analysis: '.implode('; ', $res['messages']));
            $this->assertEmpty($res['messages']);
        }

        // Now apply with admin
        $counts = $importer->apply($results, $admin);
        $this->assertSame(2, $counts['created']);
        $this->assertSame(0, $counts['skipped']);

        $b1 = Book::where('title', 'Pride and Prejudice')->first();
        $this->assertNotNull($b1);
        $this->assertTrue($b1->categories->contains(fn (Category $c) => $c->slug === 'classics'));

        $b2 = Book::where('title', 'English Grammar in Use')->first();
        $this->assertNotNull($b2);
        $this->assertSame('B1', $b2->level);

        File::delete($fixturePath);
    }

    public function test_upload_catalog_covers_command_runs_idempotently_and_respects_dry_run(): void
    {
        $coversDir = storage_path('app/catalog-covers');
        File::ensureDirectoryExists($coversDir);

        Storage::fake('public');
        config(['filesystems.media_disk' => 'public']);

        // Create dummy cover
        $im = imagecreatetruecolor(320, 500);
        ob_start();
        imagejpeg($im, null, 80);
        $jpg = ob_get_clean();
        imagedestroy($im);
        File::put("{$coversDir}/9780141439518.jpg", $jpg);

        $book = Book::create(['title' => 'Pride and Prejudice', 'status' => 'live']);
        $variant = BookVariant::create([
            'book_id' => $book->id,
            'format' => 'paperback',
            'sku' => 'PRIDE-PB',
            'isbn' => '9780141439518',
            'price_usd' => '9.99',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        // Dry run: does not upload
        $this->artisan('catalog:upload-covers', ['--dry-run' => true])
            ->expectsOutputToContain('[Dry run]')
            ->assertSuccessful();

        $this->assertNull($variant->fresh()->cover_image_url);

        // Real upload
        $this->artisan('catalog:upload-covers')
            ->assertSuccessful();

        $this->assertNotNull($variant->fresh()->cover_image_url);
        Storage::disk('public')->assertExists('covers/9780141439518.jpg');

        // Second run: idempotent
        $this->artisan('catalog:upload-covers')
            ->expectsOutputToContain('Covers skipped (already on disk):')
            ->assertSuccessful();

        File::delete("{$coversDir}/9780141439518.jpg");
    }
}

<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookVariant;
use App\Models\StaffUser;
use App\Services\Catalog\BookCsvImport;
use App\Services\Catalog\CoverStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CoverStorageTest extends TestCase
{
    use RefreshDatabase;

    private function image(): string
    {
        $image = imagecreatetruecolor(300, 450);
        ob_start();
        imagepng($image);

        return str_pad(ob_get_clean(), 1024, "\0");
    }

    public function test_ingest_downloads_external_image_and_stores_on_media_disk(): void
    {
        Storage::fake('public');
        config(['filesystems.media_disk' => 'public']);

        $fakeImageData = $this->image();
        Http::fake([
            'https://covers.openlibrary.org/b/id/123-L.jpg' => Http::response($fakeImageData, 200),
        ]);

        $service = app(CoverStorageService::class);
        $source = 'https://covers.openlibrary.org/b/id/123-L.jpg';
        $resultUrl = $service->ingest($source, '9780735211292', 'SH-0001-PB');

        $path = 'covers/9780735211292-'.hash('sha256', $source).'.png';
        Storage::disk('public')->assertExists($path);
        $this->assertEquals(Storage::disk('public')->url($path), $resultUrl);
        $this->assertSame($resultUrl, $service->ingest($source, '9780735211292'));
        Http::assertSentCount(1);
    }

    public function test_sync_covers_artisan_command(): void
    {
        Storage::fake('public');
        config(['filesystems.media_disk' => 'public']);

        $fakeImageData = $this->image();
        Http::fake([
            'https://covers.openlibrary.org/b/id/456-L.jpg' => Http::response($fakeImageData, 200),
        ]);

        $book = Book::factory()->create();
        $variant = BookVariant::factory()->for($book)->create([
            'isbn' => '9780140280197',
            'cover_image_url' => 'https://covers.openlibrary.org/b/id/456-L.jpg',
        ]);

        $this->artisan('catalog:sync-covers')
            ->expectsOutputToContain('Cover synchronization complete')
            ->assertSuccessful();

        $variant->refresh();
        $this->assertStringContainsString('covers/9780140280197-', $variant->cover_image_url);
        $this->assertStringEndsWith('.png', $variant->cover_image_url);
    }

    public function test_untrusted_urls_are_never_requested_and_invalid_images_are_not_stored(): void
    {
        Storage::fake('public');
        config(['filesystems.media_disk' => 'public']);
        Http::fake(['*' => Http::response(str_repeat('not an image', 100), 200)]);
        $service = app(CoverStorageService::class);
        foreach (['http://127.0.0.1/admin', 'https://169.254.169.254/latest', 'https://covers.openlibrary.org.evil.test/a', 'https://user@covers.openlibrary.org/a', 'https://covers.openlibrary.org:8080/a'] as $url) {
            $this->assertSame($url, $service->ingest($url));
        }
        Http::assertNothingSent();
        $url = 'https://covers.openlibrary.org/b/id/123-L.jpg';
        $this->assertSame($url, $service->ingest($url));
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_sync_dry_run_never_downloads_or_changes_records(): void
    {
        Storage::fake('public');
        config(['filesystems.media_disk' => 'public']);
        Http::preventStrayRequests();
        $url = 'https://covers.openlibrary.org/b/id/123-L.jpg';
        $variant = BookVariant::factory()->create(['cover_image_url' => $url]);
        $this->artisan('catalog:sync-covers', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($url, $variant->fresh()->cover_image_url);
        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_csv_cover_copy_is_opt_in_and_uses_faked_storage_and_http(): void
    {
        Storage::fake('public');
        config(['filesystems.media_disk' => 'public', 'filesystems.ingest_catalog_covers' => false]);
        Http::fake(['*' => Http::response($this->image(), 200)]);
        $variant = BookVariant::factory()->create();
        $admin = StaffUser::factory()->admin()->create();
        $url = 'https://covers.openlibrary.org/b/id/789-L.jpg';
        $importer = app(BookCsvImport::class);
        $records = [2 => ['sku' => $variant->sku, 'cover_image_url' => $url]];
        $importer->apply($importer->analyse($records), $admin);
        $this->assertSame($url, $variant->fresh()->cover_image_url);
        Http::assertNothingSent();
        config(['filesystems.ingest_catalog_covers' => true]);
        $importer->apply($importer->analyse($records), $admin);
        $this->assertNotSame($url, $variant->fresh()->cover_image_url);
        Http::assertSentCount(1);
        $this->assertCount(1, Storage::disk('public')->allFiles());
    }

    public function test_changed_source_has_distinct_path_and_force_refreshes_cache(): void
    {
        Storage::fake('public');
        config(['filesystems.media_disk' => 'public']);
        Http::fake(['*' => Http::response($this->image(), 200)]);
        $service = app(CoverStorageService::class);
        $first = 'https://covers.openlibrary.org/b/id/111-L.jpg';
        $second = 'https://covers.openlibrary.org/b/id/222-L.jpg';
        $original = $service->ingest($first, '9780140280197');
        $this->assertNotSame($original, $service->ingest($second, '9780140280197'));
        $this->assertSame($original, $service->ingest($first, '9780140280197', null, true));
        Http::assertSentCount(3);
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }
}

<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookVariant;
use App\Services\Catalog\CoverStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CoverStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_ingest_downloads_external_image_and_stores_on_media_disk(): void
    {
        Storage::fake('public');
        config(['filesystems.media_disk' => 'public']);

        $fakeImageData = str_repeat('A', 1024);
        Http::fake([
            'https://example.com/cover.jpg' => Http::response($fakeImageData, 200),
        ]);

        $service = app(CoverStorageService::class);
        $resultUrl = $service->ingest('https://example.com/cover.jpg', '9780735211292', 'SH-0001-PB');

        Storage::disk('public')->assertExists('covers/9780735211292.jpg');
        $this->assertEquals(Storage::disk('public')->url('covers/9780735211292.jpg'), $resultUrl);
    }

    public function test_sync_covers_artisan_command(): void
    {
        Storage::fake('public');
        config(['filesystems.media_disk' => 'public']);

        $fakeImageData = str_repeat('B', 1024);
        Http::fake([
            'https://example.com/book.jpg' => Http::response($fakeImageData, 200),
        ]);

        $book = Book::factory()->create();
        $variant = BookVariant::factory()->for($book)->create([
            'isbn' => '9780140280197',
            'cover_image_url' => 'https://example.com/book.jpg',
        ]);

        $this->artisan('catalog:sync-covers')
            ->expectsOutputToContain('Cover synchronization complete')
            ->assertSuccessful();

        $variant->refresh();
        $this->assertStringContainsString('covers/9780140280197.jpg', $variant->cover_image_url);
    }
}

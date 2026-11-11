<?php

namespace Tests\Feature\Catalog;

use App\Models\BookVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MirrorExternalCoversTest extends TestCase
{
    use RefreshDatabase;

    private const COVER = 'https://covers.openlibrary.org/b/isbn/9780141439518-L.jpg';

    private const MISSING = 'https://covers.openlibrary.org/b/isbn/0000000000-L.jpg';

    private const NOT_IMAGE = 'https://example.org/page.html';

    protected function setUp(): void
    {
        parent::setUp();
        config(['filesystems.media_disk' => 'public']);
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake([
            self::COVER => Http::response(str_repeat('J', 4096), 200, ['Content-Type' => 'image/jpeg']),
            self::MISSING => Http::response('GIF89a', 200, ['Content-Type' => 'image/gif']),
            self::NOT_IMAGE => Http::response('<html></html>', 200, ['Content-Type' => 'text/html']),
        ]);
    }

    public function test_copies_an_external_cover_once_and_updates_every_variant_that_uses_it(): void
    {
        $a = BookVariant::factory()->create(['cover_image_url' => self::COVER]);
        $b = BookVariant::factory()->create(['cover_image_url' => self::COVER]);

        $this->artisan('catalog:mirror-covers', ['--pause' => 0])->assertSuccessful();

        Http::assertSentCount(1);
        $url = $a->fresh()->cover_image_url;
        $this->assertStringStartsWith(Storage::disk('public')->url('covers/'), $url);
        $this->assertSame($url, $b->fresh()->cover_image_url);
        $path = 'covers/'.date('Y/m').'/'.sha1(self::COVER).'.jpg';
        Storage::disk('public')->assertExists($path);
    }

    public function test_keeps_the_old_link_when_the_download_is_not_a_real_cover(): void
    {
        $missing = BookVariant::factory()->create(['cover_image_url' => self::MISSING]);
        $page = BookVariant::factory()->create(['cover_image_url' => self::NOT_IMAGE]);

        $this->artisan('catalog:mirror-covers', ['--pause' => 0])->assertSuccessful();

        $this->assertSame(self::MISSING, $missing->fresh()->cover_image_url);
        $this->assertSame(self::NOT_IMAGE, $page->fresh()->cover_image_url);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_dry_run_changes_nothing_and_downloads_nothing(): void
    {
        $variant = BookVariant::factory()->create(['cover_image_url' => self::COVER]);

        $this->artisan('catalog:mirror-covers', ['--dry-run' => true])
            ->expectsOutputToContain(self::COVER)
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(self::COVER, $variant->fresh()->cover_image_url);
    }

    public function test_leaves_covers_already_on_the_media_disk_and_empty_covers_alone(): void
    {
        $own = Storage::disk('public')->url('covers/2026/10/already.jpg');
        $mine = BookVariant::factory()->create(['cover_image_url' => $own]);
        $none = BookVariant::factory()->create(['cover_image_url' => null]);

        $this->artisan('catalog:mirror-covers', ['--pause' => 0])->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame($own, $mine->fresh()->cover_image_url);
        $this->assertNull($none->fresh()->cover_image_url);
    }
}

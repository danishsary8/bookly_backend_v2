<?php

namespace Tests\Feature\Catalog;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Promotion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Replaced and never-saved cover pictures are removed from the media disk; anything still used stays (owner 2026-11-15). */
class PruneUnusedCoversTest extends TestCase
{
    use RefreshDatabase;

    private function store(string $path, int $daysOld): string
    {
        $disk = Storage::disk('public');
        $disk->put($path, 'img');
        touch($disk->path($path), now()->subDays($daysOld)->getTimestamp());

        return $disk->url($path);
    }

    public function test_it_deletes_only_old_files_that_nothing_uses(): void
    {
        config(['filesystems.media_disk' => 'public']);
        Storage::fake('public');

        $variant = BookVariant::factory()->create(['cover_image_url' => $this->store('covers/2026/11/current.jpg', 30)]);
        $trashed = Book::factory()->create();
        BookVariant::factory()->create(['book_id' => $trashed->id, 'cover_image_url' => $this->store('covers/2026/10/trashed-book.jpg', 30)]);
        $trashed->delete();
        Author::factory()->create(['photo_url' => $this->store('covers/2026/11/author.jpg', 30)]);
        Promotion::factory()->create(['image_url' => $this->store('covers/2026/11/promo.jpg', 30)]);
        $this->store('covers/9780062852687.jpg', 30); // mirrored ISBN cover
        BookVariant::factory()->create(['cover_image_url' => Storage::disk('public')->url('covers/9780062852687.jpg')]);
        $this->store('covers/2026/11/replaced.jpg', 30);
        $this->store('covers/2026/11/never-saved.jpg', 9);
        $this->store('covers/2026/11/just-uploaded.jpg', 1); // someone may still be filling in the form

        $this->artisan('media:prune-covers --dry-run')->expectsOutputToContain('2 unused')->assertSuccessful();
        Storage::disk('public')->assertExists('covers/2026/11/replaced.jpg');

        $this->artisan('media:prune-covers')->assertSuccessful();

        Storage::disk('public')->assertMissing(['covers/2026/11/replaced.jpg', 'covers/2026/11/never-saved.jpg']);
        Storage::disk('public')->assertExists(['covers/2026/11/current.jpg', 'covers/2026/10/trashed-book.jpg', 'covers/2026/11/author.jpg',
            'covers/2026/11/promo.jpg', 'covers/9780062852687.jpg', 'covers/2026/11/just-uploaded.jpg']);
        $this->assertNotNull($variant->fresh()->cover_image_url);
    }

    public function test_it_stops_when_it_would_delete_too_much(): void
    {
        config(['filesystems.media_disk' => 'public']);
        Storage::fake('public');
        BookVariant::factory()->create(['cover_image_url' => $this->store('covers/keep.jpg', 30)]);
        foreach (['a', 'b', 'c'] as $name) {
            $this->store("covers/old-{$name}.jpg", 30);
        }

        $this->artisan('media:prune-covers')->assertFailed();
        Storage::disk('public')->assertExists('covers/old-a.jpg');

        $this->artisan('media:prune-covers --force')->assertSuccessful();
        Storage::disk('public')->assertMissing('covers/old-a.jpg');
        Storage::disk('public')->assertExists('covers/keep.jpg');
    }

    public function test_it_deletes_nothing_when_no_picture_is_in_use(): void
    {
        config(['filesystems.media_disk' => 'public']);
        Storage::fake('public');
        $this->store('covers/a.jpg', 30);

        $this->artisan('media:prune-covers')->assertFailed();
        Storage::disk('public')->assertExists('covers/a.jpg');
    }
}

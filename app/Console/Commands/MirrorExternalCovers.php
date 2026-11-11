<?php

namespace App\Console\Commands;

use App\Models\BookVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copies book covers that still point to another website (for example covers.openlibrary.org) onto the shop's own
 * media disk (Cloudflare R2 in production, see config/filesystems.php) and points the book variants at the copy.
 *
 * Each distinct link is downloaded once (variants of one book usually share it). A download is only used when it is a
 * real image: an image content type (JPEG, PNG or WebP), at least 1 KB (Open Library answers a missing cover with a
 * tiny placeholder) and at most 5 MB. Anything else is reported and the variant keeps its old link. Safe to run again:
 * links already on the media disk are left alone.
 */
class MirrorExternalCovers extends Command
{
    protected $signature = 'catalog:mirror-covers
        {--dry-run : List what would be copied without downloading or changing anything}
        {--limit=0 : Copy at most this many distinct links (0 = all)}
        {--pause=300 : Milliseconds to wait between downloads (be polite to the image host)}';

    protected $description = 'Copies book covers hosted on other websites to the media disk (R2) and updates the book variants.';

    private const MIN_BYTES = 1024;

    private const MAX_BYTES = 5 * 1024 * 1024;

    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function handle(): int
    {
        $diskName = (string) config('filesystems.media_disk', 'public');
        $disk = Storage::disk($diskName);
        $ownBase = rtrim($disk->url(''), '/');
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(0, (int) $this->option('limit'));
        $pause = max(0, (int) $this->option('pause'));

        // Distinct external links: not empty, http(s), and not already on our media disk.
        $links = BookVariant::query()
            ->whereNotNull('cover_image_url')
            ->where('cover_image_url', 'like', 'http%')
            ->where('cover_image_url', 'not like', $ownBase.'%')
            ->distinct()
            ->orderBy('cover_image_url')
            ->pluck('cover_image_url');

        if ($limit > 0) {
            $links = $links->take($limit);
        }

        $this->info("Media disk: {$diskName} ({$ownBase})");
        $this->info("Covers on other websites: {$links->count()} distinct link(s).");
        if ($dryRun) {
            $this->warn('Dry run: nothing is downloaded and no book is changed.');
            foreach ($links as $link) {
                $count = BookVariant::where('cover_image_url', $link)->count();
                $this->line("- {$link} ({$count} variant(s))");
            }

            return self::SUCCESS;
        }

        $copied = 0;
        $variantsUpdated = 0;
        $failed = [];

        foreach ($links->values() as $i => $link) {
            if ($i > 0 && $pause > 0) {
                usleep($pause * 1000);
            }

            try {
                $response = Http::timeout(20)->retry(2, 500, throw: false)->get($link);
            } catch (\Throwable $e) {
                $failed[] = [$link, 'download failed: '.Str::limit($e->getMessage(), 80)];

                continue;
            }

            $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            $body = $response->body();
            $size = strlen($body);

            if (! $response->successful()) {
                $failed[] = [$link, "HTTP {$response->status()}"];

                continue;
            }
            if (! isset(self::TYPES[$type])) {
                $failed[] = [$link, "not a JPEG/PNG/WebP image ({$type})"];

                continue;
            }
            if ($size < self::MIN_BYTES) {
                $failed[] = [$link, "too small ({$size} bytes): probably no cover at the source"];

                continue;
            }
            if ($size > self::MAX_BYTES) {
                $failed[] = [$link, 'larger than 5 MB'];

                continue;
            }

            // Same folder layout as covers uploaded in the admin (covers/YYYY/MM); the name comes from the link, so a
            // second run writes the same file instead of a duplicate.
            $path = 'covers/'.date('Y/m').'/'.sha1($link).'.'.self::TYPES[$type];
            $disk->put($path, $body);
            $url = $disk->url($path);

            $variantsUpdated += BookVariant::where('cover_image_url', $link)->update(['cover_image_url' => $url]);
            $copied++;
            $this->line("Copied {$link} -> {$url}");
        }

        $this->newLine();
        $this->info("Copied: {$copied} cover(s); book variants updated: {$variantsUpdated}.");
        if ($failed !== []) {
            $this->warn('Not copied (these books keep their old link): '.count($failed));
            $this->table(['Link', 'Reason'], $failed);
        }

        return self::SUCCESS;
    }
}

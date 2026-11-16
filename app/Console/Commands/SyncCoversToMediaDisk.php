<?php

namespace App\Console\Commands;

use App\Models\BookVariant;
use App\Services\Catalog\CoverStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SyncCoversToMediaDisk extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:sync-covers {--force : Re-download and upload even if already on media disk}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download all external book cover URLs and upload them to the configured media disk (Cloudflare R2 or public).';

    public function handle(CoverStorageService $coverStorage): int
    {
        $mediaDisk = (string) config('filesystems.media_disk', 'public');
        $this->info("Starting cover sync to target media disk: [{$mediaDisk}]");

        $disk = Storage::disk($mediaDisk);
        $diskBaseUrl = '';
        try {
            $diskBaseUrl = $disk->url('');
        } catch (\Throwable) {}

        $variants = BookVariant::whereNotNull('cover_image_url')
            ->where('cover_image_url', '!=', '')
            ->get();

        if ($variants->isEmpty()) {
            $this->info('No book variants with covers found in the database.');
            return Command::SUCCESS;
        }

        $this->info("Found {$variants->count()} book variant(s) with covers.");
        $bar = $this->output->createProgressBar($variants->count());
        $bar->start();

        $synced = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($variants as $variant) {
            $currentUrl = $variant->cover_image_url;

            // If not forcing and already on disk URL, skip
            if (! $this->option('force') && ! empty($diskBaseUrl) && str_starts_with($currentUrl, $diskBaseUrl)) {
                $skipped++;
                $bar->advance();
                continue;
            }

            // Only process valid web URLs
            if (! str_starts_with($currentUrl, 'http://') && ! str_starts_with($currentUrl, 'https://')) {
                $skipped++;
                $bar->advance();
                continue;
            }

            $newUrl = $coverStorage->ingest($currentUrl, $variant->isbn, $variant->sku);

            if ($newUrl !== $currentUrl) {
                $variant->update(['cover_image_url' => $newUrl]);
                $synced++;
            } else {
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Cover synchronization complete:");
        $this->line("- Newly synced to [{$mediaDisk}]: {$synced}");
        $this->line("- Already on disk / skipped: {$skipped}");
        $this->line("- Retained original URL / unchanged: {$failed}");

        return Command::SUCCESS;
    }
}

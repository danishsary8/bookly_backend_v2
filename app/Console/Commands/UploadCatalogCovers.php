<?php

namespace App\Console\Commands;

use App\Models\BookVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class UploadCatalogCovers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:upload-covers {--dry-run : Report actions without modifying disk or database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Uploads local catalog cover images to configured media disk (R2/public) and updates book variants.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $mediaDisk = (string) config('filesystems.media_disk', 'public');
        $coversDir = storage_path('app/catalog-covers');

        $this->info("Scanning catalog covers in {$coversDir} (target disk: {$mediaDisk})");
        if ($dryRun) {
            $this->warn('Running in --dry-run mode. No files will be uploaded and no database records changed.');
        }

        if (! File::isDirectory($coversDir)) {
            $this->warn("Directory {$coversDir} does not exist or has no covers.");

            return Command::SUCCESS;
        }

        $files = File::files($coversDir);
        $totalFiles = count($files);
        $this->info("Found {$totalFiles} cover image(s) to process.");

        $uploaded = 0;
        $variantsUpdated = 0;
        $skipped = 0;

        $disk = Storage::disk($mediaDisk);

        foreach ($files as $file) {
            $filename = $file->getFilename();
            $isbn = pathinfo($filename, PATHINFO_FILENAME);
            $cleanIsbn = strtoupper(preg_replace('/[^0-9X]/i', '', $isbn));

            $targetPath = "covers/{$filename}";

            // Check if already exists on disk
            $existsOnDisk = false;
            try {
                $existsOnDisk = $disk->exists($targetPath);
            } catch (\Throwable) {
                // Ignore inspection errors and proceed
            }

            if (! $dryRun) {
                if (! $existsOnDisk) {
                    $stream = fopen($file->getRealPath(), 'r');
                    $disk->put($targetPath, $stream);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                    $uploaded++;
                } else {
                    $skipped++;
                }

                $coverUrl = $disk->url($targetPath);

                $updated = BookVariant::whereRaw("upper(regexp_replace(coalesce(isbn, ''), '[^0-9Xx]', '', 'g')) = ?", [$cleanIsbn])
                    ->where(function ($query) use ($coverUrl) {
                        $query->whereNull('cover_image_url')
                            ->orWhere('cover_image_url', '!=', $coverUrl);
                    })
                    ->update(['cover_image_url' => $coverUrl]);

                $variantsUpdated += $updated;
            } else {
                $uploaded++;
                $variantsCount = BookVariant::whereRaw("upper(regexp_replace(coalesce(isbn, ''), '[^0-9Xx]', '', 'g')) = ?", [$cleanIsbn])
                    ->count();
                $variantsUpdated += $variantsCount;
            }
        }

        $modePrefix = $dryRun ? '[Dry run] ' : '';
        $this->info("{$modePrefix}Processing complete:");
        $this->line("- Covers uploaded: {$uploaded}");
        $this->line("- Covers skipped (already on disk): {$skipped}");
        $this->line("- Book variants updated with cover URL: {$variantsUpdated}");

        return Command::SUCCESS;
    }
}

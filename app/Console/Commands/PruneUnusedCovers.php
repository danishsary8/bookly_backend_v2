<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes images in the media disk's `covers/` folder (Cloudflare R2 in production) that nothing uses any more:
 * covers that were replaced, and pictures uploaded in the staff area but never saved (owner 2026-11-15).
 *
 * A file is kept when any of these still points at it — deleted (trashed) rows included, so a restored book keeps its
 * cover: book variant covers, author photos, gift and promotion pictures, and newsletter texts. Files newer than
 * --days are always kept (someone may be filling in a form right now). Safety stops: nothing is deleted when no
 * picture is in use at all (a wrong database), or when more than half the files would go, unless --force is given;
 * both stops only warn (exit 0), so the weekly job is not retried over and over.
 * Run with --dry-run first to see the list.
 */
class PruneUnusedCovers extends Command
{
    protected $signature = 'media:prune-covers
        {--dry-run : List the unused files without deleting anything}
        {--days=7 : Keep files newer than this many days}
        {--force : Delete even when more than half of the files are unused}';

    protected $description = 'Deletes cover images in the media disk that no book, author, gift, promotion or newsletter uses.';

    private const FOLDER = 'covers';

    /** Table => picture columns (rows in the trash count as in use). */
    private const COLUMNS = [
        'book_variants' => ['cover_image_url'],
        'authors' => ['photo_url'],
        'gifts' => ['image_url'],
        'promotions' => ['image_url'],
    ];

    /** Free text that may contain a picture link. */
    private const TEXTS = [
        'newsletter_campaigns' => ['body_en', 'body_km'],
    ];

    public function handle(): int
    {
        $disk = Storage::disk((string) config('filesystems.media_disk', 'public'));
        $days = max(0, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days)->getTimestamp();

        $used = $this->usedPaths();
        $texts = $this->texts();
        if ($used === [] && $texts === '') {
            // A safety stop, not an error: the weekly run must not be retried every few minutes.
            $this->warn('No picture is in use in the database: nothing deleted, in case this is the wrong database.');

            return self::SUCCESS;
        }

        $files = $disk->allFiles(self::FOLDER);
        $unused = [];
        foreach ($files as $path) {
            if (isset($used[$path]) || ($texts !== '' && str_contains($texts, $path))) {
                continue;
            }
            if ($disk->lastModified($path) > $cutoff) {
                continue;
            }
            $unused[] = $path;
        }

        $this->info(count($files).' file(s) in '.self::FOLDER.'/, '.count($unused)." unused and older than {$days} day(s).");
        foreach ($unused as $path) {
            $this->line("- {$path}");
        }
        if ($dryRun || $unused === []) {
            if ($dryRun) {
                $this->warn('Dry run: nothing was deleted.');
            }

            return self::SUCCESS;
        }
        if (count($unused) * 2 > count($files) && ! $this->option('force')) {
            $this->warn('More than half of the files would be deleted: nothing deleted. Check with --dry-run, then use --force.');

            return self::SUCCESS;
        }

        foreach (array_chunk($unused, 500) as $chunk) {
            $disk->delete($chunk);
        }
        $this->info('Deleted '.count($unused).' file(s).');

        return self::SUCCESS;
    }

    /** @return array<string, true> object paths (covers/…) that a row points at */
    private function usedPaths(): array
    {
        $used = [];
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                DB::table($table)->whereNotNull($column)->distinct()->pluck($column)->each(function ($url) use (&$used) {
                    $path = $this->pathOf((string) $url);
                    if ($path !== null) {
                        $used[$path] = true;
                    }
                });
            }
        }

        return $used;
    }

    private function texts(): string
    {
        $all = '';
        foreach (self::TEXTS as $table => $columns) {
            foreach (DB::table($table)->get($columns) as $row) {
                foreach ($columns as $column) {
                    $all .= ' '.($row->{$column} ?? '');
                }
            }
        }

        return trim($all);
    }

    /** "https://pub-….r2.dev/covers/2026/11/a.jpg" or "/storage/covers/…" → "covers/2026/11/a.jpg". */
    private function pathOf(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }
        $at = strpos($path, '/'.self::FOLDER.'/');
        if ($at === false) {
            return str_starts_with($path, self::FOLDER.'/') ? rawurldecode($path) : null;
        }

        return rawurldecode(substr($path, $at + 1));
    }
}

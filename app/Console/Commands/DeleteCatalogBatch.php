<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\BookVariant;
use App\Models\StaffUser;
use App\Services\Admin\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DeleteCatalogBatch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:delete-batch {csv : Path to the CSV batch file} {--dry-run : Only show what would be trashed} {--staff= : Active administrator ID recorded in the audit log} {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Moves fully matched catalog books to Trash, retaining editions and historical records';

    public function handle(): int
    {
        $csvPath = $this->argument('csv');
        if (! is_file($csvPath) || ! is_readable($csvPath)) {
            $this->error("File not found: {$csvPath}");

            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $fp = fopen($csvPath, 'r');
        $headers = fgetcsv($fp, null, ',', '"', '');
        if (! is_array($headers)) {
            fclose($fp);
            $this->error('CSV header is missing.');

            return Command::FAILURE;
        }
        $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
        $skuIdx = array_search('sku', $headers, true);

        if ($skuIdx === false) {
            $this->error("CSV file does not contain a 'sku' column.");
            fclose($fp);

            return Command::FAILURE;
        }

        $skus = [];
        while (($row = fgetcsv($fp, null, ',', '"', '')) !== false) {
            $sku = trim($row[$skuIdx] ?? '');
            if ($sku !== '') {
                $skus[] = $sku;
            }
        }
        fclose($fp);

        if (empty($skus)) {
            $this->warn("No SKUs found in {$csvPath}.");

            return Command::SUCCESS;
        }

        $variants = BookVariant::whereHas('book')->whereIn('sku', $skus)->get();
        $variantCount = $variants->count();
        $bookIds = $variants->pluck('book_id')->unique()->values()->all();
        $bookCount = count($bookIds);
        if (BookVariant::whereIn('book_id', $bookIds)->whereNotIn('sku', $skus)->exists()) {
            $this->error('Batch only matches some editions of a book. Include all its SKUs; no books changed.');

            return Command::FAILURE;
        }

        $this->info("Found {$variantCount} variant(s) belonging to {$bookCount} book(s) matching SKUs in {$csvPath}.");

        if ($variantCount === 0) {
            $this->info('No matching books found in database (they may already be deleted).');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('[Dry run] No records deleted.');

            return Command::SUCCESS;
        }

        $admin = StaffUser::find($this->option('staff'));
        if (! $admin || ! $admin->is_active || ! $admin->isAdmin()) {
            $this->error('Provide --staff with an active administrator ID for audit attribution.');

            return Command::FAILURE;
        }
        if (! $force && ! $this->confirm("Move these {$bookCount} books to Trash, retaining editions and history?", false)) {
            $this->line('Operation cancelled.');

            return Command::SUCCESS;
        }

        DB::transaction(function () use ($bookIds, $admin) {
            foreach (Book::whereIn('id', $bookIds)->get() as $book) {
                $before = ['title' => $book->title, 'deleted_at' => null];
                $book->delete();
                app(AuditLogger::class)->custom($admin, 'deleted', $book, $before, [
                    'title' => $book->title, 'deleted_at' => $book->deleted_at, 'source' => 'catalog:delete-batch',
                ]);
            }
        });

        $this->info("Moved {$bookCount} book(s) to Trash. Editions and historical records are retained.");

        return Command::SUCCESS;
    }
}

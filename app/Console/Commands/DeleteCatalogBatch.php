<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Models\BookVariant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DeleteCatalogBatch extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:delete-batch {csv : Path to the CSV batch file} {--dry-run : Only show what would be deleted} {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deletes books and variants specified in a catalog CSV batch';

    public function handle(): int
    {
        $csvPath = $this->argument('csv');
        if (! File::exists($csvPath)) {
            $this->error("File not found: {$csvPath}");

            return Command::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $fp = fopen($csvPath, 'r');
        $headers = fgetcsv($fp);
        $skuIdx = array_search('sku', $headers, true);

        if ($skuIdx === false) {
            $this->error("CSV file does not contain a 'sku' column.");
            fclose($fp);

            return Command::FAILURE;
        }

        $skus = [];
        while (($row = fgetcsv($fp)) !== false) {
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

        $variants = BookVariant::whereIn('sku', $skus)->get();
        $variantCount = $variants->count();
        $bookIds = $variants->pluck('book_id')->unique()->values()->all();
        $bookCount = count($bookIds);

        $this->info("Found {$variantCount} variant(s) belonging to {$bookCount} book(s) matching SKUs in {$csvPath}.");

        if ($variantCount === 0) {
            $this->info('No matching books found in database (they may already be deleted).');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('[Dry run] No records deleted.');

            return Command::SUCCESS;
        }

        if (! $force && ! $this->confirm("Are you sure you want to permanently delete these {$bookCount} books and {$variantCount} variants?", true)) {
            $this->line('Operation cancelled.');

            return Command::SUCCESS;
        }

        DB::transaction(function () use ($bookIds, $skus) {
            // Delete cart items if any
            DB::table('cart_items')->whereIn('book_variant_id', function ($q) use ($skus) {
                $q->select('id')->from('book_variants')->whereIn('sku', $skus);
            })->delete();

            // Delete book relations
            DB::table('book_categories')->whereIn('book_id', $bookIds)->delete();
            DB::table('book_authors')->whereIn('book_id', $bookIds)->delete();
            DB::table('reviews')->whereIn('book_id', $bookIds)->delete();

            // Delete variants
            BookVariant::whereIn('sku', $skus)->forceDelete();

            // Delete books that have no more remaining variants
            foreach ($bookIds as $bookId) {
                if (! BookVariant::where('book_id', $bookId)->exists()) {
                    Book::where('id', $bookId)->forceDelete();
                }
            }
        });

        $this->info("Successfully deleted {$bookCount} book(s) and {$variantCount} variant(s) from the database.");

        return Command::SUCCESS;
    }
}

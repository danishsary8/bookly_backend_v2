<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\AI\EmbeddingService;
use Illuminate\Console\Command;

class EmbedBooksCommand extends Command
{
    protected $signature = 'books:embed
                            {--all : Process all catalog books}
                            {--limit= : Limit the number of books to process}
                            {--force : Force re-embedding even if text hash has not changed}
                            {--dry-run : Check which books need embedding without saving vectors}';

    protected $description = 'Generate and update AI embeddings for catalog books';

    public function handle(EmbeddingService $service): int
    {
        $all = (bool) $this->option('all');
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');

        if (! $all && $limit === null) {
            $this->warn('Please specify --all to process all books or --limit=N to process a batch.');

            return self::FAILURE;
        }

        $query = Book::query()->with(['categories', 'authors'])->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        $books = $query->get();
        $total = $books->count();

        if ($total === 0) {
            $this->info('No books found to process.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[DRY RUN] ' : '')."Processing {$total} book(s)...");

        $summary = $service->embedBatch($books, force: $force, dryRun: $dryRun);

        $this->newLine();
        $this->table(
            ['Status', 'Count'],
            [
                ['Embedded', $summary['embedded']],
                ['Unchanged (skipped)', $summary['unchanged']],
                ['Failed', $summary['failed']],
                ['Total Processed', $total],
            ]
        );

        if ($dryRun) {
            $this->comment('Dry run complete. No database changes were made.');
        } else {
            $this->info('Embedding run complete.');
        }

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

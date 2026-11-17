<?php

namespace App\Services\AI;

use App\Models\Book;
use App\Models\BookEmbedding;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmbeddingService
{
    public const RESULT_EMBEDDED = 'embedded';

    public const RESULT_UNCHANGED = 'unchanged';

    public const RESULT_FAILED = 'failed';

    public function __construct(
        private EmbeddingClient $client,
        private ?string $model = null,
    ) {
        $this->model = $model ?: (string) config('shop.ai_embed_model', 'text-embedding-004');
    }

    /**
     * Build the normalized representation of a book for semantic embedding.
     */
    public function buildBookText(Book $book): string
    {
        $book->loadMissing(['categories', 'authors']);

        $parts = [];
        $parts[] = 'Title: '.trim($book->title);

        if (! empty($book->subtitle)) {
            $parts[] = 'Subtitle: '.trim($book->subtitle);
        }

        if (! empty($book->description)) {
            $parts[] = 'Description: '.trim($book->description);
        }

        $categoryNames = $book->categories
            ->pluck('name')
            ->filter()
            ->sort()
            ->values()
            ->all();

        if (! empty($categoryNames)) {
            $parts[] = 'Categories: '.implode(', ', $categoryNames);
        }

        $authorNames = $book->authors
            ->pluck('name')
            ->filter()
            ->sort()
            ->values()
            ->all();

        if (! empty($authorNames)) {
            $parts[] = 'Authors: '.implode(', ', $authorNames);
        }

        return implode("\n", $parts);
    }

    /**
     * Compute the SHA-256 hash of a book's normalized text.
     */
    public function computeTextHash(Book $book): string
    {
        return hash('sha256', $this->buildBookText($book));
    }

    /**
     * Embed a single book, skipping if text hash has not changed.
     *
     * @return string self::RESULT_EMBEDDED | self::RESULT_UNCHANGED | self::RESULT_FAILED
     */
    public function embedBook(Book $book, bool $force = false): string
    {
        $text = $this->buildBookText($book);
        $textHash = hash('sha256', $text);

        $existing = BookEmbedding::where('book_id', $book->id)->first();
        if (! $force && $existing && $existing->text_hash === $textHash) {
            return self::RESULT_UNCHANGED;
        }

        try {
            $vector = $this->client->embed($text);
            if (empty($vector)) {
                return self::RESULT_FAILED;
            }

            BookEmbedding::updateOrCreate(
                ['book_id' => $book->id],
                [
                    'embedding' => $vector,
                    'model' => $this->model,
                    'text_hash' => $textHash,
                    'embedded_at' => now(),
                ]
            );

            return self::RESULT_EMBEDDED;
        } catch (Throwable $e) {
            Log::warning('Failed to embed book', [
                'book_id' => $book->id,
                'error' => $e->getMessage(),
            ]);

            return self::RESULT_FAILED;
        }
    }

    /**
     * Batch embed multiple books.
     *
     * @param  iterable<Book>  $books
     * @return array{embedded: int, unchanged: int, failed: int}
     */
    public function embedBatch(iterable $books, bool $force = false, bool $dryRun = false): array
    {
        $summary = ['embedded' => 0, 'unchanged' => 0, 'failed' => 0];

        $pendingBooks = [];
        $pendingTexts = [];
        $pendingHashes = [];

        // Check text hash first to avoid needless API calls
        foreach ($books as $book) {
            /** @var Book $book */
            $text = $this->buildBookText($book);
            $hash = hash('sha256', $text);

            $existing = BookEmbedding::where('book_id', $book->id)->first();
            if (! $force && $existing && $existing->text_hash === $hash) {
                $summary['unchanged']++;

                continue;
            }

            if ($dryRun) {
                $summary['embedded']++;

                continue;
            }

            $pendingBooks[] = $book;
            $pendingTexts[] = $text;
            $pendingHashes[] = $hash;
        }

        if ($dryRun || empty($pendingBooks)) {
            return $summary;
        }

        // Process pending in chunks of 50
        $chunks = array_chunk(array_keys($pendingBooks), 50);

        foreach ($chunks as $indices) {
            $chunkTexts = array_map(fn ($i) => $pendingTexts[$i], $indices);

            try {
                $vectors = $this->client->embedBatch($chunkTexts);

                foreach ($indices as $offset => $i) {
                    $book = $pendingBooks[$i];
                    $vector = $vectors[$offset] ?? null;

                    if (! $vector) {
                        $summary['failed']++;

                        continue;
                    }

                    BookEmbedding::updateOrCreate(
                        ['book_id' => $book->id],
                        [
                            'embedding' => $vector,
                            'model' => $this->model,
                            'text_hash' => $pendingHashes[$i],
                            'embedded_at' => now(),
                        ]
                    );

                    $summary['embedded']++;
                }
            } catch (Throwable $e) {
                Log::warning('Failed to embed book batch', [
                    'count' => count($chunkTexts),
                    'error' => $e->getMessage(),
                ]);

                $summary['failed'] += count($chunkTexts);
            }
        }

        return $summary;
    }
}

<?php

namespace App\Services\AI;

use App\Enums\BookFormat;
use App\Models\Book;
use App\Models\BookEmbedding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class SemanticSearchService
{
    public function __construct(
        private EmbeddingClient $client
    ) {}

    /**
     * Search books by semantic meaning of the query text.
     *
     * @param  list<int>  $excludeIds
     * @return list<Book>
     */
    public function searchByMeaning(string $term, array $excludeIds = [], int $limit = 8, ?string $ip = null): array
    {
        if (! config('shop.ai_search_enabled')) {
            return [];
        }

        $words = preg_split('/\s+/u', trim($term), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 3) {
            return [];
        }

        // Rate limit: 30 AI searches per minute per IP
        $clientIp = $ip ?: '127.0.0.1';
        $rateLimitKey = 'ai_search:'.$clientIp;
        if (RateLimiter::tooManyAttempts($rateLimitKey, 30)) {
            Log::info('Semantic search rate limited', ['ip' => $clientIp]);

            return [];
        }
        RateLimiter::hit($rateLimitKey, 60);

        try {
            $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', trim($term)));
            $cacheKey = 'ai_query_embed:'.hash('sha256', $normalized);

            // Cache query embedding 24 hours with a 2-second timeout
            $vector = Cache::remember($cacheKey, now()->addHours(24), function () use ($normalized) {
                return $this->client->embed($normalized, 2);
            });

            if (empty($vector) || count($vector) !== EmbeddingClient::DIMENSIONS) {
                return [];
            }

            $threshold = (float) config('shop.ai_similarity_threshold', 0.40);

            $hasVector = false;
            try {
                $hasVector = DB::table('pg_extension')->where('extname', 'vector')->exists();
            } catch (Throwable) {
                $hasVector = false;
            }

            if ($hasVector) {
                $vectorStr = '['.implode(',', $vector).']';

                $rawIds = DB::table('book_embeddings')
                    ->whereNotIn('book_id', $excludeIds)
                    ->whereRaw('(embedding <=> ?) <= ?', [$vectorStr, $threshold])
                    ->orderByRaw('embedding <=> ? ASC', [$vectorStr])
                    ->limit($limit * 2)
                    ->pluck('book_id')
                    ->all();

                $validIds = array_slice($this->filterInStockAndVisibleIds($rawIds), 0, $limit);
            } else {
                $validIds = $this->querySimilarInMemory($vector, $excludeIds, $limit, $threshold);
            }

            if (empty($validIds)) {
                return [];
            }

            $books = Book::query()->forCards()
                ->whereIn('books.id', $validIds)
                ->get();

            // Preserve nearest distance order
            $ordered = [];
            foreach ($validIds as $id) {
                $b = $books->firstWhere('id', $id);
                if ($b) {
                    $ordered[] = $b;
                }
            }

            return $ordered;
        } catch (Throwable $e) {
            Log::warning('Semantic search failed', [
                'term' => $term,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Filter book IDs to only those that are in-stock and visible.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function filterInStockAndVisibleIds(array $ids): array
    {
        if (empty($ids)) {
            return [];
        }

        $activeIds = Book::query()
            ->visible()
            ->whereHas('activeVariants', fn ($v) => $v->where(fn ($w) => $w
                ->where('stock_quantity', '>', 0)
                ->orWhereIn('format', [BookFormat::Ebook->value, BookFormat::Audiobook->value])))
            ->whereIn('books.id', $ids)
            ->pluck('books.id')
            ->all();

        $activeLookup = array_flip($activeIds);

        return array_values(array_filter($ids, fn ($id) => isset($activeLookup[$id])));
    }

    /**
     * In-memory cosine distance fallback for test/dev environments without pgvector.
     *
     * @param  list<float>  $queryVector
     * @param  list<int>  $excludeIds
     * @return list<int>
     */
    private function querySimilarInMemory(array $queryVector, array $excludeIds, int $limit, float $threshold): array
    {
        $candidates = BookEmbedding::query()
            ->whereNotIn('book_id', $excludeIds)
            ->get(['book_id', 'embedding']);

        $scored = [];
        foreach ($candidates as $candidate) {
            $candidateVector = $candidate->embedding;
            if (empty($candidateVector) || count($candidateVector) !== count($queryVector)) {
                continue;
            }

            $dot = 0.0;
            for ($i = 0, $len = count($queryVector); $i < $len; $i++) {
                $dot += $queryVector[$i] * $candidateVector[$i];
            }
            $distance = 1.0 - $dot;

            if ($distance <= $threshold) {
                $scored[] = ['id' => (int) $candidate->book_id, 'distance' => $distance];
            }
        }

        usort($scored, fn ($a, $b) => $a['distance'] <=> $b['distance']);
        $rawIds = array_column($scored, 'id');

        return array_slice($this->filterInStockAndVisibleIds($rawIds), 0, $limit);
    }
}

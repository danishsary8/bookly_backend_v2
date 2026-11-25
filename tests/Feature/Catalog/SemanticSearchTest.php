<?php

namespace Tests\Feature\Catalog;

use App\Enums\BookFormat;
use App\Models\Book;
use App\Models\BookEmbedding;
use App\Models\BookVariant;
use App\Services\AI\EmbeddingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Tests\TestCase;

class SemanticSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        RateLimiter::clear('ai_search:127.0.0.1');
    }

    private function makeBuyableBook(string $title, string $status = 'live', int $stock = 5, string $description = 'A book for testing'): Book
    {
        $book = Book::factory()->create([
            'title' => $title,
            'status' => $status,
            'description' => $description,
        ]);

        BookVariant::factory()->create([
            'book_id' => $book->id,
            'format' => BookFormat::Paperback,
            'is_active' => true,
            'stock_quantity' => $stock,
        ]);

        return $book;
    }

    /**
     * Create a deterministic 768-dim unit vector with given first 2 coordinates.
     *
     * @return list<float>
     */
    private function unitVector(float $x, float $y): array
    {
        $v = array_fill(0, 768, 0.0);
        $v[0] = $x;
        $v[1] = $y;

        $norm = sqrt(array_sum(array_map(fn ($val) => $val * $val, $v)));
        if ($norm > 0) {
            $v = array_map(fn ($val) => (float) ($val / $norm), $v);
        }

        return $v;
    }

    private function attachEmbedding(Book $book, array $vector): BookEmbedding
    {
        return BookEmbedding::updateOrCreate(
            ['book_id' => $book->id],
            [
                'embedding' => $vector,
                'model' => 'text-embedding-004',
                'text_hash' => hash('sha256', $book->title),
                'embedded_at' => now(),
            ]
        );
    }

    public function test_ai_search_is_off_by_default(): void
    {
        Config::set('shop.ai_search_enabled', false);

        $book = $this->makeBuyableBook('Philosophy of Peaceful Mind', 'live', 5);
        $this->attachEmbedding($book, $this->unitVector(1.0, 0.0));

        // Keyword search does not match "deep meditation guide"
        $res = $this->getJson('/api/v1/books?q=deep+meditation+guide')->assertOk();
        $this->assertCount(0, $res->json('data'));
    }

    public function test_ai_search_appends_meaning_matches_for_three_plus_word_queries(): void
    {
        Config::set('shop.ai_search_enabled', true);

        // Pre-cache query embedding pointing along X axis [1.0, 0.0, ...]
        $normalizedQuery = 'deep meditation guide';
        $cacheKey = 'ai_query_embed:'.hash('sha256', $normalizedQuery);
        Cache::put($cacheKey, $this->unitVector(1.0, 0.0), now()->addHours(24));

        // Semantically similar book (cosine distance ~0.02 <= 0.40)
        $semanticBook = $this->makeBuyableBook('Philosophy of Peaceful Mind', 'live', 5);
        $this->attachEmbedding($semanticBook, $this->unitVector(0.98, 0.20));

        // Dissimilar book (cosine distance ~0.90 > 0.40)
        $dissimilarBook = $this->makeBuyableBook('Automotive Engine Repair', 'live', 5);
        $this->attachEmbedding($dissimilarBook, $this->unitVector(0.10, 0.99));

        $res = $this->getJson('/api/v1/books?q=deep+meditation+guide')->assertOk();
        $items = $res->json('data');

        $this->assertCount(1, $items);
        $this->assertSame($semanticBook->id, $items[0]['id']);
        $this->assertSame('meaning', $items[0]['match']);
    }

    public function test_ai_search_skips_queries_with_fewer_than_three_words(): void
    {
        Config::set('shop.ai_search_enabled', true);

        $normalizedQuery = 'deep meditation';
        $cacheKey = 'ai_query_embed:'.hash('sha256', $normalizedQuery);
        Cache::put($cacheKey, $this->unitVector(1.0, 0.0), now()->addHours(24));

        $semanticBook = $this->makeBuyableBook('Philosophy of Peaceful Mind', 'live', 5);
        $this->attachEmbedding($semanticBook, $this->unitVector(0.98, 0.20));

        // Two-word query should skip AI search entirely
        $res = $this->getJson('/api/v1/books?q=deep+meditation')->assertOk();
        $this->assertCount(0, $res->json('data'));
    }

    public function test_ai_search_does_not_duplicate_books_found_by_keywords(): void
    {
        Config::set('shop.ai_search_enabled', true);

        $normalizedQuery = 'guide to calm';
        $cacheKey = 'ai_query_embed:'.hash('sha256', $normalizedQuery);
        Cache::put($cacheKey, $this->unitVector(1.0, 0.0), now()->addHours(24));

        // Book that matches all keywords "guide", "to", "calm" directly in title
        $keywordBook = $this->makeBuyableBook('Guide to Calm Living', 'live', 5);
        $this->attachEmbedding($keywordBook, $this->unitVector(0.99, 0.05));

        // Another semantic-only book that doesn't match the keyword query
        $semanticBook = $this->makeBuyableBook('Peaceful Mind and Zen', 'live', 5);
        $this->attachEmbedding($semanticBook, $this->unitVector(0.97, 0.22));

        $res = $this->getJson('/api/v1/books?q=guide+to+calm')->assertOk();
        $items = $res->json('data');

        // Should have 2 books total: the keyword match (no match: meaning) followed by semantic match (match: meaning)
        $this->assertCount(2, $items);
        $this->assertSame($keywordBook->id, $items[0]['id']);
        $this->assertArrayNotHasKey('match', $items[0]);

        $this->assertSame($semanticBook->id, $items[1]['id']);
        $this->assertSame('meaning', $items[1]['match']);
    }

    public function test_ai_search_excludes_out_of_stock_and_hidden_books(): void
    {
        Config::set('shop.ai_search_enabled', true);

        $normalizedQuery = 'deep meditation guide';
        $cacheKey = 'ai_query_embed:'.hash('sha256', $normalizedQuery);
        Cache::put($cacheKey, $this->unitVector(1.0, 0.0), now()->addHours(24));

        // Out of stock book
        $outOfStock = $this->makeBuyableBook('Out of Stock Zen', 'live', 0);
        $this->attachEmbedding($outOfStock, $this->unitVector(0.99, 0.05));

        // Hidden / draft book
        $hidden = $this->makeBuyableBook('Draft Secret Zen', 'draft', 10);
        $this->attachEmbedding($hidden, $this->unitVector(0.99, 0.05));

        // Valid available book
        $available = $this->makeBuyableBook('Living with Serenity', 'live', 5);
        $this->attachEmbedding($available, $this->unitVector(0.98, 0.15));

        $res = $this->getJson('/api/v1/books?q=deep+meditation+guide')->assertOk();
        $items = $res->json('data');

        $this->assertCount(1, $items);
        $this->assertSame($available->id, $items[0]['id']);
        $this->assertSame('meaning', $items[0]['match']);
    }

    public function test_ai_search_rate_limited_to_30_per_minute_per_ip(): void
    {
        Config::set('shop.ai_search_enabled', true);

        $normalizedQuery = 'deep meditation guide';
        $cacheKey = 'ai_query_embed:'.hash('sha256', $normalizedQuery);
        Cache::put($cacheKey, $this->unitVector(1.0, 0.0), now()->addHours(24));

        $book = $this->makeBuyableBook('Philosophy of Peaceful Mind', 'live', 5);
        $this->attachEmbedding($book, $this->unitVector(0.98, 0.20));

        // Simulate 30 calls
        for ($i = 0; $i < 30; $i++) {
            RateLimiter::hit('ai_search:127.0.0.1', 60);
        }

        // 31st call should be rate-limited and skip AI search, returning normal keyword results only
        $res = $this->getJson('/api/v1/books?q=deep+meditation+guide')->assertOk();
        $this->assertCount(0, $res->json('data'));
    }

    public function test_ai_search_error_falls_back_to_normal_results(): void
    {
        Config::set('shop.ai_search_enabled', true);

        // Bind mock client that throws an exception (e.g. timeout / network error)
        $mockClient = $this->createMock(EmbeddingClient::class);
        $mockClient->method('embed')->willThrowException(new RuntimeException('Request timed out after 2s'));
        $this->app->instance(EmbeddingClient::class, $mockClient);

        // Keyword book matching all query terms "mindfulness", "in", "action"
        $book = $this->makeBuyableBook('Mindfulness in Daily Action', 'live', 5);

        // Search with 3+ words: normal keyword results should still return cleanly
        $res = $this->getJson('/api/v1/books?q=mindfulness+in+action')->assertOk();
        $items = $res->json('data');

        $this->assertCount(1, $items);
        $this->assertSame($book->id, $items[0]['id']);
    }

    public function test_ai_query_embedding_is_cached_for_twenty_four_hours(): void
    {
        Config::set('shop.ai_search_enabled', true);

        // Create the book first: with AI search on, a new book is embedded too, and only query embeddings count here.
        $book = $this->makeBuyableBook('Philosophy of Peaceful Mind', 'live', 5);
        $this->attachEmbedding($book, $this->unitVector(0.98, 0.20));

        $mockClient = $this->createMock(EmbeddingClient::class);
        $mockClient->expects($this->once())
            ->method('embed')
            ->willReturn($this->unitVector(1.0, 0.0));

        $this->app->instance(EmbeddingClient::class, $mockClient);

        // First call should invoke embed client once
        $this->getJson('/api/v1/books?q=deep+meditation+guide')->assertOk();

        // Second call should hit the 24-hour cache and NOT invoke the client again
        $this->getJson('/api/v1/books?q=deep+meditation+guide')->assertOk();
    }
}

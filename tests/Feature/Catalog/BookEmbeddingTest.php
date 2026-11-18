<?php

namespace Tests\Feature\Catalog;

use App\Jobs\EmbedBook;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookEmbedding;
use App\Models\Category;
use App\Services\AI\EmbeddingClient;
use App\Services\AI\EmbeddingService;
use App\Services\AI\FakeEmbeddingClient;
use App\Services\AI\GeminiEmbeddingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BookEmbeddingTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_embedding_client_returns_deterministic_768_unit_vectors(): void
    {
        $client = new FakeEmbeddingClient;

        $vector1 = $client->embed('The Great Gatsby');
        $vector2 = $client->embed('The Great Gatsby');
        $vector3 = $client->embed('A completely different book title');

        $this->assertCount(EmbeddingClient::DIMENSIONS, $vector1);
        $this->assertEquals(768, count($vector1));

        // Deterministic: same text yields same vector
        $this->assertSame($vector1, $vector2);

        // Different text yields different vector
        $this->assertNotSame($vector1, $vector3);

        // Normalized to unit length (sum of squares is ~1.0)
        $norm = array_sum(array_map(fn ($x) => $x * $x, $vector1));
        $this->assertEqualsWithDelta(1.0, $norm, 0.01);
    }

    public function test_gemini_client_formats_request_and_extracts_embeddings(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'embeddings' => [
                    ['values' => array_fill(0, 768, 0.05)],
                ],
            ], 200),
        ]);

        $client = new GeminiEmbeddingClient(apiKey: 'dummy-test-key', model: 'text-embedding-004');
        $vector = $client->embed('Test Book Title');

        $this->assertCount(768, $vector);
        $this->assertEquals(0.05, $vector[0]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return str_contains($request->url(), 'text-embedding-004:batchEmbedContents')
                && isset($data['requests'][0]['outputDimensionality'])
                && $data['requests'][0]['outputDimensionality'] === 768;
        });
    }

    public function test_gemini_client_handles_failures_without_logging_key_or_text(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'Rate limit'], 429),
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                // Must not contain the sensitive API key or raw book text
                return $message === 'Gemini embedding request failed'
                    && isset($context['status'])
                    && $context['status'] === 429
                    && ! isset($context['key'])
                    && ! isset($context['apiKey'])
                    && ! isset($context['text']);
            });

        $client = new GeminiEmbeddingClient(apiKey: 'secret-api-key', model: 'text-embedding-004');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Gemini embedding API returned HTTP 429');

        $client->embed('Secret Unreleased Book Plot');
    }

    public function test_embedding_service_builds_text_including_authors_and_categories(): void
    {
        $book = Book::factory()->create([
            'title' => 'The Pragmatic Programmer',
            'description' => 'A guide to software craftsmanship.',
        ]);

        $cat1 = Category::factory()->create(['name' => 'Technology']);
        $cat2 = Category::factory()->create(['name' => 'Programming']);
        $book->categories()->attach([$cat1->id, $cat2->id]);

        $author = Author::factory()->create(['name' => 'Andy Hunt']);
        $book->authors()->attach([$author->id]);

        $service = new EmbeddingService(new FakeEmbeddingClient);
        $text = $service->buildBookText($book);

        $this->assertStringContainsString('Title: The Pragmatic Programmer', $text);
        $this->assertStringContainsString('Description: A guide to software craftsmanship.', $text);
        $this->assertStringContainsString('Categories: Programming, Technology', $text);
        $this->assertStringContainsString('Authors: Andy Hunt', $text);
    }

    public function test_embedding_service_embeds_and_skips_unchanged_text(): void
    {
        $book = Book::factory()->create(['title' => 'Clean Code']);
        $service = new EmbeddingService(new FakeEmbeddingClient);

        // First run: should embed
        $result1 = $service->embedBook($book);
        $this->assertSame(EmbeddingService::RESULT_EMBEDDED, $result1);

        $this->assertDatabaseHas('book_embeddings', [
            'book_id' => $book->id,
            'model' => 'text-embedding-004',
        ]);

        $embeddingRecord = BookEmbedding::where('book_id', $book->id)->firstOrFail();
        $this->assertCount(768, $embeddingRecord->embedding);
        $initialHash = $embeddingRecord->text_hash;

        // Second run without changes: should be unchanged
        $result2 = $service->embedBook($book);
        $this->assertSame(EmbeddingService::RESULT_UNCHANGED, $result2);

        // Force run: should re-embed
        $result3 = $service->embedBook($book, force: true);
        $this->assertSame(EmbeddingService::RESULT_EMBEDDED, $result3);
        $this->assertSame($initialHash, BookEmbedding::where('book_id', $book->id)->first()->text_hash);
    }

    public function test_queued_job_embed_book_runs_when_enabled(): void
    {
        Config::set('shop.ai_similar_enabled', true);

        $book = Book::factory()->create(['title' => 'Refactoring']);

        $job = new EmbedBook($book->id);
        $job->handle(new EmbeddingService(new FakeEmbeddingClient));

        $this->assertDatabaseHas('book_embeddings', [
            'book_id' => $book->id,
        ]);
    }

    public function test_queued_job_embed_book_skips_when_disabled(): void
    {
        Config::set('shop.ai_similar_enabled', false);

        $book = Book::factory()->create(['title' => 'Design Patterns']);

        $job = new EmbedBook($book->id);
        $job->handle(new EmbeddingService(new FakeEmbeddingClient));

        $this->assertDatabaseMissing('book_embeddings', [
            'book_id' => $book->id,
        ]);
    }

    public function test_artisan_command_requires_all_or_limit(): void
    {
        $this->artisan('books:embed')
            ->expectsOutputToContain('Please specify --all to process all books or --limit=N to process a batch.')
            ->assertFailed();
    }

    public function test_artisan_command_dry_run_does_not_save_embeddings(): void
    {
        Book::factory()->count(3)->create();

        $this->artisan('books:embed --all --dry-run')
            ->expectsOutputToContain('[DRY RUN] Processing 3 book(s)...')
            ->expectsOutputToContain('Dry run complete. No database changes were made.')
            ->assertSuccessful();

        $this->assertSame(0, BookEmbedding::count());
    }

    public function test_artisan_command_embeds_all_books_and_skips_on_rerun(): void
    {
        Book::factory()->count(4)->create();

        // First pass: embeds all 4
        $this->artisan('books:embed --all')
            ->expectsOutputToContain('Processing 4 book(s)...')
            ->assertSuccessful();

        $this->assertSame(4, BookEmbedding::count());

        // Second pass: all 4 are unchanged
        $this->artisan('books:embed --all')
            ->expectsTable(
                ['Status', 'Count'],
                [
                    ['Embedded', 0],
                    ['Unchanged (skipped)', 4],
                    ['Failed', 0],
                    ['Total Processed', 4],
                ]
            )
            ->assertSuccessful();
    }

    public function test_artisan_command_limit_option_processes_specified_count(): void
    {
        Book::factory()->count(5)->create();

        $this->artisan('books:embed --limit=2')
            ->expectsOutputToContain('Processing 2 book(s)...')
            ->assertSuccessful();

        $this->assertSame(2, BookEmbedding::count());
    }
}

<?php

namespace Tests\Feature\Catalog;

use App\Enums\BookFormat;
use App\Models\Book;
use App\Models\BookEmbedding;
use App\Models\BookVariant;
use App\Models\Series;
use App\Services\Catalog\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SimilarBooksRecommendationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function makeBuyableBook(string $title, string $status = 'live', int $stock = 5, ?int $seriesId = null, ?int $seriesOrder = null): Book
    {
        $book = Book::factory()->create([
            'title' => $title,
            'status' => $status,
            'series_id' => $seriesId,
            'series_order' => $seriesOrder,
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
     * Create a deterministic 768-dim unit vector with the given first 2 values.
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

    public function test_similar_books_appear_after_series_and_mark_reason_as_similar(): void
    {
        Config::set('shop.ai_similar_enabled', true);

        $series = Series::factory()->create(['name' => 'Epic Trilogy']);

        // Target book: Volume 1 in series, vector pointing mainly along X axis [1.0, 0.0, ...]
        $target = $this->makeBuyableBook('Epic Vol 1', 'live', 5, $series->id, 1);
        $this->attachEmbedding($target, $this->unitVector(1.0, 0.0));

        // Series next book: Volume 2 (series match takes precedence)
        $seriesVol2 = $this->makeBuyableBook('Epic Vol 2', 'live', 5, $series->id, 2);

        // Semantically similar book (dot product ~0.98, cosine distance ~0.02 <= 0.40)
        $similarBook = $this->makeBuyableBook('Very Similar Magic Novel', 'live', 5);
        $this->attachEmbedding($similarBook, $this->unitVector(0.98, 0.20));

        // Dissimilar book (dot product ~0.10, cosine distance ~0.90 > 0.40)
        $dissimilarBook = $this->makeBuyableBook('Unrelated Cooking Recipes', 'live', 5);
        $this->attachEmbedding($dissimilarBook, $this->unitVector(0.10, 0.99));

        $res = $this->getJson("/api/v1/books/{$target->id}/related")->assertOk();
        $items = $res->json('data');

        $ids = collect($items)->pluck('id')->all();

        // 1) Series volume 2 must come first
        $this->assertSame($seriesVol2->id, $ids[0]);

        // 2) Semantically similar book must come second (before other fallbacks)
        $this->assertSame($similarBook->id, $ids[1]);

        // 3) Dissimilar book is NOT matched as similar
        $dissimilarItem = collect($items)->firstWhere('id', $dissimilarBook->id);
        $this->assertArrayNotHasKey('recommendation_reason', $dissimilarItem ?? []);

        // Also directly verify querySemanticSimilarBookIds excludes it
        $semanticIds = app(RecommendationService::class)->querySemanticSimilarBookIds($target);
        $this->assertContains($similarBook->id, $semanticIds);
        $this->assertNotContains($dissimilarBook->id, $semanticIds);

        // 4) Target book is never recommended to itself
        $this->assertNotContains($target->id, $ids);

        // 5) Semantic match is marked with recommendation_reason: "similar"
        $similarItem = collect($items)->firstWhere('id', $similarBook->id);
        $this->assertSame('similar', $similarItem['recommendation_reason'] ?? null);

        // Series item does not have recommendation_reason: "similar"
        $seriesItem = collect($items)->firstWhere('id', $seriesVol2->id);
        $this->assertArrayNotHasKey('recommendation_reason', $seriesItem);
    }

    public function test_similar_books_fallback_to_original_rules_when_feature_disabled(): void
    {
        Config::set('shop.ai_similar_enabled', false);

        $target = $this->makeBuyableBook('Target Book');
        $this->attachEmbedding($target, $this->unitVector(1.0, 0.0));

        $similarBook = $this->makeBuyableBook('Similar Book');
        $this->attachEmbedding($similarBook, $this->unitVector(0.99, 0.10));

        $res = $this->getJson("/api/v1/books/{$target->id}/related")->assertOk();
        $items = $res->json('data');

        // When disabled, no items have recommendation_reason: "similar"
        foreach ($items as $item) {
            $this->assertArrayNotHasKey('recommendation_reason', $item);
        }
    }

    public function test_missing_vector_on_target_book_falls_back_gracefully_without_error(): void
    {
        Config::set('shop.ai_similar_enabled', true);

        // Target book has NO embedding record
        $target = $this->makeBuyableBook('Book Without Vector');

        $other = $this->makeBuyableBook('Another Book');
        $this->attachEmbedding($other, $this->unitVector(1.0, 0.0));

        $res = $this->getJson("/api/v1/books/{$target->id}/related");
        $res->assertOk();
    }

    public function test_hidden_and_out_of_stock_books_are_never_recommended_by_similarity(): void
    {
        Config::set('shop.ai_similar_enabled', true);

        $target = $this->makeBuyableBook('Live In-Stock Target');
        $this->attachEmbedding($target, $this->unitVector(1.0, 0.0));

        // Identical vector, but draft (hidden)
        $draftBook = $this->makeBuyableBook('Draft Similar Book', 'draft', 10);
        $this->attachEmbedding($draftBook, $this->unitVector(1.0, 0.0));

        // Identical vector, but out of stock (stock_quantity = 0)
        $outOfStockBook = $this->makeBuyableBook('Out of Stock Similar Book', 'live', 0);
        $this->attachEmbedding($outOfStockBook, $this->unitVector(1.0, 0.0));

        $res = $this->getJson("/api/v1/books/{$target->id}/related")->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertNotContains($draftBook->id, $ids);
        $this->assertNotContains($outOfStockBook->id, $ids);
    }

    public function test_recommendations_cache_refreshes_on_catalog_change(): void
    {
        Config::set('shop.ai_similar_enabled', true);

        $target = $this->makeBuyableBook('Target Book');
        $this->attachEmbedding($target, $this->unitVector(1.0, 0.0));

        $book1 = $this->makeBuyableBook('First Candidate');
        $this->attachEmbedding($book1, $this->unitVector(0.98, 0.1));

        $res1 = $this->getJson("/api/v1/books/{$target->id}/related")->assertOk();
        $this->assertContains($book1->id, collect($res1->json('data'))->pluck('id')->all());

        // Invalidate catalog cache
        RecommendationService::catalogChanged();

        $res2 = $this->getJson("/api/v1/books/{$target->id}/related")->assertOk();
        $this->assertContains($book1->id, collect($res2->json('data'))->pluck('id')->all());
    }
}

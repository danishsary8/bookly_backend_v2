<?php

namespace Tests\Feature\Catalog;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** "Series first" in the book page list (/books/{id}/related) and the cart list (/catalog/recommendations). */
class SeriesRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function book(array $attributes = [], int $stock = 10, ?Category $category = null): Book
    {
        $book = Book::factory()->create(['status' => 'live', ...$attributes]);
        BookVariant::factory()->create(['book_id' => $book->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => $stock]);
        if ($category) {
            $book->categories()->attach($category->id);
        }

        return $book;
    }

    /** @return list<Book> books 1..$count of one series, in reading order */
    private function series(int $count, ?Category $category = null): array
    {
        $series = Series::factory()->create();

        return array_map(
            fn (int $n) => $this->book(['series_id' => $series->id, 'series_order' => $n], 10, $category),
            range(1, $count),
        );
    }

    /** @return list<int> */
    private function ids(string $url): array
    {
        return collect($this->getJson($url)->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_book_page_lists_the_next_books_of_the_series_first_then_the_earlier_ones(): void
    {
        $category = Category::factory()->create();
        [$one, $two, $three, $four, $five] = $this->series(5, $category);
        $other = $this->book([], 10, $category);

        $ids = $this->ids("/api/v1/books/{$three->id}/related");

        $this->assertSame([$four->id, $five->id, $one->id, $two->id], array_slice($ids, 0, 4));
        $this->assertContains($other->id, $ids);
        $this->assertNotContains($three->id, $ids);
        $this->assertSame(array_values(array_unique($ids)), $ids);
    }

    public function test_book_page_skips_sold_out_series_books(): void
    {
        [$one, $two] = $this->series(2);
        $series = $one->series_id;
        $soldOut = $this->book(['series_id' => $series, 'series_order' => 3], 0);
        $four = $this->book(['series_id' => $series, 'series_order' => 4]);

        $ids = $this->ids("/api/v1/books/{$two->id}/related");

        $this->assertSame([$four->id, $one->id], $ids);
        $this->assertNotContains($soldOut->id, $ids);
    }

    public function test_book_with_few_matches_gets_same_category_best_sellers_then_store_best_sellers_up_to_the_limit(): void
    {
        $philosophy = Category::factory()->create();
        $history = Category::factory()->create();

        // A "Meditations"-like book: only 2 in-stock books share its category.
        $target = $this->book([], 10, $philosophy);
        $sibling = $this->book([], 10, $philosophy);
        $bestSibling = $this->book([], 10, $philosophy);
        $soldOutSibling = $this->book([], 0, $philosophy);

        // 13 books elsewhere in the store; one is the store's best seller.
        $store = array_map(fn () => $this->book([], 10, $history), range(1, 13));
        $storeBest = $store[0];

        $order = Order::factory()->create(['status' => OrderStatus::Delivered]);
        OrderItem::factory()->create(['order_id' => $order->id, 'book_variant_id' => $bestSibling->variants()->value('id'), 'quantity' => 2]);
        OrderItem::factory()->create(['order_id' => $order->id, 'book_variant_id' => $storeBest->variants()->value('id'), 'quantity' => 5]);

        $ids = $this->ids("/api/v1/books/{$target->id}/related?limit=12");

        // Same category first (its best seller first), even though the store best seller sold more.
        $this->assertSame([$bestSibling->id, $sibling->id, $storeBest->id], array_slice($ids, 0, 3));
        // Then store books, newest first (no sales, no ratings), until the row is full.
        $rest = collect($store)->slice(1)->pluck('id')->sortDesc()->values()->take(9)->all();
        $this->assertSame($rest, array_slice($ids, 3));
        $this->assertCount(12, $ids);
        $this->assertNotContains($target->id, $ids);
        $this->assertNotContains($soldOutSibling->id, $ids);
        $this->assertSame(array_values(array_unique($ids)), $ids);
    }

    public function test_book_page_order_is_series_then_category_then_author_then_store(): void
    {
        $category = Category::factory()->create();
        $author = Author::factory()->create();

        $storeBook = $this->book();
        $sameAuthor = $this->book();
        $sameAuthor->authors()->attach($author->id);
        $sameCategory = $this->book([], 10, $category);
        $categoryAndAuthor = $this->book([], 10, $category);
        $categoryAndAuthor->authors()->attach($author->id);
        [$one, $two] = $this->series(2);

        $one->categories()->attach($category->id);
        $one->authors()->attach($author->id);

        $ids = $this->ids("/api/v1/books/{$one->id}/related?limit=12");

        $this->assertSame([$two->id, $categoryAndAuthor->id, $sameCategory->id, $sameAuthor->id, $storeBook->id], $ids);
    }

    public function test_cart_suggests_the_next_book_of_the_series_first_and_never_a_cart_book(): void
    {
        $category = Category::factory()->create();
        [$one, $two, $three, $four] = $this->series(4, $category);
        $other = $this->book([], 10, $category);

        // Books 1 and 2 in the cart: next is 3, then 4; never 1 or 2.
        $ids = $this->ids("/api/v1/catalog/recommendations?cart_ids={$one->id},{$two->id}");

        $this->assertSame([$three->id, $four->id], array_slice($ids, 0, 2));
        $this->assertNotContains($one->id, $ids);
        $this->assertNotContains($two->id, $ids);
        $this->assertContains($other->id, $ids);
        $this->assertSame(array_values(array_unique($ids)), $ids);
    }

    public function test_cart_with_a_later_book_suggests_the_next_one_then_the_earlier_ones(): void
    {
        [$one, $two, $three, $four] = $this->series(4);

        $ids = $this->ids("/api/v1/catalog/recommendations?cart_ids={$three->id}");

        $this->assertSame([$four->id, $one->id, $two->id], array_slice($ids, 0, 3));
    }

    public function test_cart_with_two_series_suggests_the_next_book_of_each_series_first(): void
    {
        [$a1, $a2, $a3, $a4] = $this->series(4);
        [$b1, $b2, $b3] = $this->series(3);

        $ids = $this->ids("/api/v1/catalog/recommendations?cart_ids={$a1->id},{$b2->id}");

        // Round 1: next of A (2) and next of B (3); round 2: A3 and B1 (B's earlier book); round 3: A4.
        $this->assertSame([$a2->id, $b3->id, $a3->id, $b1->id, $a4->id], array_slice($ids, 0, 5));
        $this->assertNotContains($a1->id, $ids);
        $this->assertNotContains($b2->id, $ids);
    }

    public function test_cart_skips_sold_out_series_books_and_respects_exclude_ids(): void
    {
        [$one, $two, $three, $four] = $this->series(4);
        $two->variants()->update(['stock_quantity' => 0]);
        $newer = $this->book(); // newest id: the store fallback would put it first

        $ids = $this->ids("/api/v1/catalog/recommendations?cart_ids={$one->id}&exclude_ids={$three->id}");

        $this->assertSame([$four->id, $newer->id], array_slice($ids, 0, 2));
        $this->assertNotContains($two->id, $ids);
        $this->assertNotContains($three->id, $ids);
        $this->assertNotContains($one->id, $ids);
    }
}

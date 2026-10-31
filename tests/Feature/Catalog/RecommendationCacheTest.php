<?php

namespace Tests\Feature\Catalog;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** The book page list is cached per catalogue version; stock and visibility are re-checked on every read. */
class RecommendationCacheTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

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

    /** A book made without model events, so it does not bump the catalogue version (like a change no one saved). */
    private function quietBook(?Category $category = null): Book
    {
        return Book::withoutEvents(fn () => $this->book([], 10, $category));
    }

    /** @return list<int> */
    private function ids(Book $book, int $limit = 12): array
    {
        return collect($this->getJson("/api/v1/books/{$book->id}/related?limit={$limit}")->assertOk()->json('data'))->pluck('id')->all();
    }

    public function test_a_book_that_sells_out_is_left_out_even_when_the_list_is_cached(): void
    {
        $category = Category::factory()->create();
        $target = $this->book([], 10, $category);
        $sibling = $this->book([], 10, $category);
        $other = $this->book([], 10, $category);

        $this->assertSame([$other->id, $sibling->id], $this->ids($target));

        // Stock changes do not bump the version: the cached list is reused, but the sold-out book is filtered out.
        DB::table('book_variants')->where('book_id', $sibling->id)->update(['stock_quantity' => 0]);
        $this->assertSame([$other->id], $this->ids($target));

        // Hidden after caching (status straight in the database, no event): also filtered on read.
        DB::table('books')->where('id', $other->id)->update(['status' => 'draft']);
        $this->assertSame([], $this->ids($target));
    }

    public function test_the_cached_list_is_cut_to_the_requested_limit(): void
    {
        $category = Category::factory()->create();
        $target = $this->book([], 10, $category);
        $books = array_map(fn () => $this->book([], 10, $category), range(1, 5));

        $all = $this->ids($target, 12);
        $this->assertCount(5, $all);
        $this->assertSame(array_slice($all, 0, 2), $this->ids($target, 2));
        $this->assertEqualsCanonicalizing(collect($books)->pluck('id')->all(), $all);
    }

    public function test_the_cache_is_used_while_nothing_changes_and_any_staff_book_edit_refreshes_it(): void
    {
        $category = Category::factory()->create();
        $target = $this->book([], 10, $category);
        $sibling = $this->book([], 10, $category);
        $unrelated = $this->book();

        $this->assertSame([$sibling->id, $unrelated->id], $this->ids($target));

        // A new matching book that nobody saved through the app: the cached list is reused, so it does not show.
        $newcomer = $this->quietBook($category);
        $this->assertSame([$sibling->id, $unrelated->id], $this->ids($target));

        // Staff edit any book (here the unrelated one): every list starts fresh and the new book shows.
        $this->asToken($this->staffToken())->patchJson("/api/v1/staff/books/{$unrelated->id}", ['title' => 'New title'])->assertOk();

        $this->assertSame([$newcomer->id, $sibling->id, $unrelated->id], $this->ids($target));
    }

    public function test_staff_moving_book_x_into_a_series_updates_book_y_list(): void
    {
        $series = Series::factory()->create();
        $y = $this->book(['series_id' => $series->id, 'series_order' => 1]);
        $x = $this->book();
        $newest = $this->book(); // the store fallback puts the newest book first

        $this->assertSame([$newest->id, $x->id], $this->ids($y));

        $this->asToken($this->staffToken())
            ->patchJson("/api/v1/staff/books/{$x->id}", ['series_id' => $series->id, 'series_order' => 2])
            ->assertOk();

        $this->assertSame([$x->id, $newest->id], $this->ids($y));
    }

    public function test_staff_changing_only_the_categories_of_book_x_updates_book_y_list(): void
    {
        $category = Category::factory()->create();
        $y = $this->book([], 10, $category);
        $x = $this->book();
        $newest = $this->book();

        $this->assertSame([$newest->id], $this->ids($y, 1));

        $this->asToken($this->staffToken())
            ->patchJson("/api/v1/staff/books/{$x->id}", ['category_ids' => [$category->id]])
            ->assertOk();

        $this->assertSame([$x->id], $this->ids($y, 1));
    }

    public function test_staff_bulk_category_change_updates_other_books_lists(): void
    {
        $category = Category::factory()->create();
        $y = $this->book([], 10, $category);
        $x = $this->book();
        $newest = $this->book();

        $this->assertSame([$newest->id], $this->ids($y, 1));

        $this->asToken($this->staffToken())
            ->postJson('/api/v1/staff/books/bulk', ['ids' => [$x->id], 'add_category_ids' => [$category->id]])
            ->assertOk();

        $this->assertSame([$x->id], $this->ids($y, 1));
    }

    public function test_best_seller_ranking_ignores_pending_cancelled_returned_and_deleted_orders(): void
    {
        $category = Category::factory()->create();
        $target = $this->book([], 10, $category);
        $pending = $this->book([], 10, $category);
        $cancelled = $this->book([], 10, $category);
        $deleted = $this->book([], 10, $category);
        $returned = $this->book([], 10, $category);
        $sold = $this->book([], 10, $category);
        $unsold = $this->book([], 10, $category);

        $sell = function (Book $book, OrderStatus $status, int $units, bool $softDelete = false): void {
            $order = Order::factory()->create(['status' => $status]);
            OrderItem::factory()->create(['order_id' => $order->id, 'book_variant_id' => $book->variants()->value('id'), 'quantity' => $units]);
            if ($softDelete) {
                $order->delete();
            }
        };
        $sell($pending, OrderStatus::Pending, 50);
        $sell($cancelled, OrderStatus::Cancelled, 50);
        $sell($deleted, OrderStatus::Delivered, 50, softDelete: true);
        $sell($returned, OrderStatus::Returned, 50);
        $sell($sold, OrderStatus::Delivered, 1);

        // Same-category best sellers: only the delivered sale counts; the rest have no sales, newest first.
        $expected = [$sold->id, $unsold->id, $returned->id, $deleted->id, $cancelled->id, $pending->id];
        $this->assertSame($expected, $this->ids($target));

        // Store best sellers (empty cart) follow the same rule.
        $popular = collect($this->getJson('/api/v1/catalog/recommendations')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame($sold->id, $popular[0]);
    }
}

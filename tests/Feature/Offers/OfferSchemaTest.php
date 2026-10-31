<?php

namespace Tests\Feature\Offers;

use App\Models\Book;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Series;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The database itself refuses bad offers (docs/superpowers/specs/2026-10-31-offers-design.md, section 3). */
class OfferSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function offer(array $overrides = []): int
    {
        return DB::table('offers')->insertGetId([
            'name' => 'Buy 2, get 15%',
            'applies_to' => 'all',
            'min_quantity' => 2,
            'percent_off' => '15.00',
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ]);
    }

    /** Runs the insert in a savepoint so a refused row doesn't break the test's transaction. */
    private function assertRefused(Closure $insert, string $why): void
    {
        try {
            DB::transaction($insert);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail("The database accepted a row it should refuse: {$why}");
    }

    public function test_a_new_offer_gets_the_defaults(): void
    {
        $row = DB::table('offers')->find($this->offer());

        $this->assertSame('buy_n_percent', $row->type);
        $this->assertSame(0, $row->used_count);
        $this->assertTrue($row->is_active);
        $this->assertNull($row->max_orders);
    }

    public function test_offers_refuse_values_outside_the_rules(): void
    {
        $this->assertRefused(fn () => $this->offer(['type' => 'bundle']), 'unknown type');
        $this->assertRefused(fn () => $this->offer(['applies_to' => 'some']), 'unknown applies_to');
        $this->assertRefused(fn () => $this->offer(['min_quantity' => 1]), 'N below 2');
        $this->assertRefused(fn () => $this->offer(['min_quantity' => 21]), 'N above 20');
        $this->assertRefused(fn () => $this->offer(['percent_off' => '0.00']), '0 %');
        $this->assertRefused(fn () => $this->offer(['percent_off' => '50.01']), 'more than 50 %');
        $this->assertRefused(
            fn () => $this->offer(['starts_at' => now(), 'ends_at' => now()->subDay()]),
            'ends before it starts',
        );

        // The edges are allowed.
        $this->offer(['min_quantity' => 20, 'percent_off' => '50.00']);
        $this->offer(['min_quantity' => 2, 'percent_off' => '0.01']);
        $this->assertSame(2, DB::table('offers')->count());
    }

    public function test_a_target_is_exactly_one_book_category_or_series_and_never_twice(): void
    {
        $offerId = $this->offer(['applies_to' => 'selected']);
        $book = Book::factory()->create();
        $category = Category::factory()->create();
        $target = fn (array $cols) => DB::table('offer_targets')->insert([
            'offer_id' => $offerId, 'created_at' => now(), 'updated_at' => now(), ...$cols,
        ]);

        $this->assertRefused(fn () => $target([]), 'a target with nothing');
        $this->assertRefused(fn () => $target(['book_id' => $book->id, 'category_id' => $category->id]), 'two things in one row');

        $target(['book_id' => $book->id]);
        $this->assertRefused(fn () => $target(['book_id' => $book->id]), 'the same book twice');
    }

    public function test_deleting_a_target_or_the_offer_removes_its_rows(): void
    {
        $offerId = $this->offer(['applies_to' => 'selected']);
        $book = Book::factory()->create();
        $series = Series::factory()->create();
        foreach ([['book_id' => $book->id], ['series_id' => $series->id]] as $cols) {
            DB::table('offer_targets')->insert(['offer_id' => $offerId, 'created_at' => now(), 'updated_at' => now(), ...$cols]);
        }

        $book->forceDelete();
        $this->assertSame(1, DB::table('offer_targets')->where('offer_id', $offerId)->count());

        DB::table('offers')->delete($offerId);
        $this->assertSame(0, DB::table('offer_targets')->count());
    }

    public function test_deleting_an_offer_keeps_the_order_and_its_copy(): void
    {
        $offerId = $this->offer();
        $order = Order::factory()->create();
        DB::table('orders')->where('id', $order->id)->update([
            'offer_id' => $offerId, 'offer_name' => 'Buy 2, get 15%', 'offer_min_quantity' => 2, 'offer_percent_off' => '15.00',
        ]);

        DB::table('offers')->delete($offerId);

        $row = DB::table('orders')->find($order->id);
        $this->assertNull($row->offer_id);
        $this->assertSame('Buy 2, get 15%', $row->offer_name);
        $this->assertSame(2, $row->offer_min_quantity);
        $this->assertSame('15.00', $row->offer_percent_off);
    }

    public function test_order_lines_start_with_no_discount(): void
    {
        $item = OrderItem::factory()->create();

        $this->assertSame('0.00', DB::table('order_items')->where('id', $item->id)->value('discount_amount'));
    }
}

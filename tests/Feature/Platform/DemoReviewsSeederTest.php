<?php

namespace Tests\Feature\Platform;

use App\Models\Book;
use App\Models\Order;
use App\Models\Review;
use Database\Seeders\DemoReviewsSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoReviewsSeederTest extends TestCase
{
    use RefreshDatabase;

    private function rated(): int
    {
        return Book::visible()->where(fn (Builder $q) => $q->whereNotNull('reader_rating')->orWhereHas('reviews'))->count();
    }

    public function test_it_rates_the_newest_unrated_books_through_delivered_orders_and_runs_only_once(): void
    {
        $this->seed(DemoSeeder::class);
        $this->assertSame(6, $this->rated());
        $orders = Order::count();
        $unrated = Book::whereDoesntHave('reviews')->orderByDesc('id')->limit(6)->pluck('id')->all();

        $this->seed(DemoReviewsSeeder::class);

        $this->assertSame(DemoReviewsSeeder::TARGET, $this->rated());
        $this->assertSame(6 + 6 * 2, Review::count());
        $this->assertEqualsCanonicalizing($unrated, Review::distinct()->whereIn('book_id', $unrated)->pluck('book_id')->all());
        // Every new review belongs to a delivered purchase of that book by the reviewer.
        Review::whereIn('book_id', $unrated)->with('orderItem.order', 'orderItem.variant')->get()->each(function (Review $review) {
            $this->assertSame('delivered', $review->orderItem->order->status->value);
            $this->assertSame($review->customer_id, $review->orderItem->order->customer_id);
            $this->assertSame($review->book_id, $review->orderItem->variant->book_id);
        });
        $this->assertGreaterThan(0, Review::whereIn('book_id', $unrated)->whereNull('comment')->count(), 'a rating without a comment is fine');
        $this->assertSame($orders + 4, Order::count(), 'one order per reviewer');

        // Running it again changes nothing.
        $this->seed(DemoReviewsSeeder::class);
        $this->assertSame(18, Review::count());
    }

    public function test_it_needs_the_demo_accounts_and_refuses_production_unless_allowed(): void
    {
        $this->artisan('db:seed', ['--class' => DemoReviewsSeeder::class])->expectsOutputToContain('Run DemoSeeder first')->assertSuccessful();

        $this->app['env'] = 'production';
        $this->artisan('db:seed', ['--class' => DemoReviewsSeeder::class, '--force' => true])->expectsOutputToContain('does not run in production')->assertSuccessful();
        $this->assertSame(0, Review::count());
    }
}

<?php

namespace Tests\Feature\Reviews;

use App\Enums\OrderStatus;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Order;
use App\Models\Review;
use Database\Seeders\TopRatedDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TopRatedDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_mock_reviews_are_repeatable_and_linked_to_the_correct_purchase(): void
    {
        $profiles = json_decode(file_get_contents(database_path('seeders/data/top_rated_reviews.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($profiles as $profile) {
            $book = Book::factory()->create(['title' => $profile['title']]);
            BookVariant::factory()->create(['book_id' => $book->id]);
        }

        $this->seed(TopRatedDemoSeeder::class);
        $count = Review::count();
        $orderCount = Order::count();
        $this->seed(TopRatedDemoSeeder::class);
        $this->assertSame($count, Review::count());
        $this->assertSame($orderCount, Order::count());

        foreach (Book::with('approvedReviews')->get() as $book) {
            $this->assertGreaterThanOrEqual(7, $book->approvedReviews->count());
            $this->assertLessThanOrEqual(10, $book->approvedReviews->count());
            $this->assertGreaterThanOrEqual(3.5, $book->approvedReviews->avg('rating'));
        }
        foreach (Review::with('orderItem.order', 'orderItem.variant')->get() as $review) {
            $this->assertSame($review->customer_id, $review->orderItem->order->customer_id);
            $this->assertSame($review->book_id, $review->orderItem->variant->book_id);
            $this->assertSame(OrderStatus::Delivered, $review->orderItem->order->status);
            $this->assertStringStartsWith('[Demo review]', $review->comment);
        }
    }

    public function test_mock_seeder_refuses_production(): void
    {
        app()->detectEnvironment(fn () => 'production');
        $this->expectException(\RuntimeException::class);
        (new TopRatedDemoSeeder)->run();
    }
}

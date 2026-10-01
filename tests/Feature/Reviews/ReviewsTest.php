<?php

namespace Tests\Feature\Reviews;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class ReviewsTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    private Customer $customer;

    private BookVariant $paperback;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::factory()->create(['name' => 'Sok Dara Chan']);
        $this->paperback = $this->physical();
        $this->withToken($this->customer->createToken('t', ['customer'])->plainTextToken);
    }

    private function review(int $rating = 5, ?string $comment = 'Loved it')
    {
        return $this->postJson("/api/v1/books/{$this->paperback->book_id}/reviews", ['rating' => $rating, 'comment' => $comment]);
    }

    public function test_only_customers_with_a_delivered_order_can_review(): void
    {
        $this->review()->assertForbidden()->assertJsonPath('message', 'You can review a book once an order containing it has been delivered.');

        $order = $this->deliveredOrder($this->customer, [[$this->paperback, 1]]);
        $order->update(['status' => OrderStatus::Shipped]);
        $this->review()->assertForbidden();

        $order->update(['status' => OrderStatus::Delivered]);
        $this->review(4, 'Great story')->assertCreated()
            ->assertJsonPath('data.rating', 4)
            ->assertJsonPath('data.reviewer_name', 'Sok C.')
            ->assertJsonPath('data.verified_purchase', true)
            ->assertJsonPath('data.is_visible', true);

        $this->assertSame($order->items[0]->id, Review::sole()->order_item_id);
    }

    public function test_any_format_of_the_book_counts_as_a_purchase(): void
    {
        $ebook = BookVariant::factory()->for($this->paperback->book)->format(BookFormat::Ebook)->create();
        $this->deliveredOrder($this->customer, [[$ebook, 1]]);

        $this->review()->assertCreated();
    }

    public function test_one_review_per_book_and_validation(): void
    {
        $this->deliveredOrder($this->customer, [[$this->paperback, 1]]);

        $this->review(6)->assertUnprocessable()->assertJsonValidationErrors('rating');
        $this->review(5)->assertCreated();
        $this->review(3)->assertStatus(409);
    }

    public function test_customer_edits_and_deletes_own_review_only(): void
    {
        $this->deliveredOrder($this->customer, [[$this->paperback, 1]]);
        $id = $this->review(5)->json('data.id');

        $this->patchJson("/api/v1/reviews/{$id}", ['rating' => 3, 'comment' => 'On second thought...'])->assertOk()->assertJsonPath('data.rating', 3);
        $this->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.book.id', $this->paperback->book_id);

        $other = Customer::factory()->create();
        $theirs = Review::create(['book_id' => $this->paperback->book_id, 'customer_id' => $other->id,
            'order_item_id' => $this->deliveredOrder($other, [[$this->paperback, 1]])->items[0]->id, 'rating' => 1]);
        $this->patchJson("/api/v1/reviews/{$theirs->id}", ['rating' => 5])->assertNotFound();
        $this->deleteJson("/api/v1/reviews/{$theirs->id}")->assertNotFound();

        $this->deleteJson("/api/v1/reviews/{$id}")->assertNoContent();
        $this->assertModelMissing(Review::make(['id' => $id]));
    }

    public function test_public_reviews_list_with_breakdown_hides_hidden_reviews(): void
    {
        $book = $this->paperback->book;
        foreach ([5, 5, 4, 2] as $rating) {
            $c = Customer::factory()->create();
            Review::create(['book_id' => $book->id, 'customer_id' => $c->id, 'rating' => $rating,
                'order_item_id' => $this->deliveredOrder($c, [[$this->paperback, 1]])->items[0]->id]);
        }
        Review::latest('id')->first()->update(['is_approved' => false]); // the 2-star one is hidden

        $this->getJson("/api/v1/books/{$book->id}/reviews")->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.rating_summary.count', 3)
            ->assertJsonPath('meta.rating_summary.average', 4.7)
            ->assertJsonPath('meta.rating_summary.distribution.5', 2)
            ->assertJsonPath('meta.rating_summary.distribution.2', 0)
            ->assertJsonMissingPath('data.0.is_visible');

        $this->getJson("/api/v1/books/{$book->id}/reviews?sort=lowest")->assertJsonPath('data.0.rating', 4);
        $this->getJson("/api/v1/books/{$book->id}/reviews?rating=5")->assertJsonCount(2, 'data');
        // The catalog's average uses the same visible reviews.
        $this->getJson("/api/v1/books/{$book->id}")->assertJsonPath('data.rating_avg', 4.7)->assertJsonPath('data.review_count', 3);
    }

    public function test_reviews_of_deleted_books_are_not_listed(): void
    {
        $this->paperback->book->delete();

        $this->getJson("/api/v1/books/{$this->paperback->book_id}/reviews")->assertNotFound();
    }
}

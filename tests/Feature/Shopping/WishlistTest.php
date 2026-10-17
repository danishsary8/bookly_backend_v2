<?php

namespace Tests\Feature\Shopping;

use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WishlistTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::factory()->create();
        $this->withToken($this->customer->createToken('t', ['customer'])->plainTextToken);
    }

    public function test_add_list_and_remove(): void
    {
        $older = BookVariant::factory()->create()->book;
        $newer = BookVariant::factory()->create()->book;

        $this->postJson('/api/v1/wishlist', ['book_id' => $older->id])
            ->assertCreated()
            ->assertJsonPath('status', 'want_to_read');

        $this->travel(1)->minutes();
        $this->postJson('/api/v1/wishlist', ['book_id' => $newer->id])->assertCreated();
        $this->postJson('/api/v1/wishlist', ['book_id' => $newer->id])->assertOk()->assertJsonPath('message', 'Already in your wishlist.');

        $this->getJson('/api/v1/wishlist')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.wishlist_status', 'want_to_read')
            ->assertJsonStructure(['data' => [['id', 'title', 'price_from_usd', 'in_stock', 'wishlist_status']], 'meta' => ['counts']]);

        $this->deleteJson("/api/v1/wishlist/{$older->id}")->assertNoContent();
        $this->getJson('/api/v1/wishlist')->assertJsonCount(1, 'data');
    }

    public function test_reading_status_lifecycle_and_filtering(): void
    {
        $book1 = BookVariant::factory()->create()->book;
        $book2 = BookVariant::factory()->create()->book;
        $book3 = BookVariant::factory()->create()->book;

        // Add book1 with default status (want_to_read)
        $this->postJson('/api/v1/wishlist', ['book_id' => $book1->id])->assertCreated();

        // Add book2 directly as reading
        $this->postJson('/api/v1/wishlist', [
            'book_id' => $book2->id,
            'status' => Wishlist::STATUS_READING,
        ])->assertCreated()->assertJsonPath('status', 'reading');

        // Add book3 as finished
        $this->postJson('/api/v1/wishlist', [
            'book_id' => $book3->id,
            'status' => Wishlist::STATUS_FINISHED,
        ])->assertCreated()->assertJsonPath('status', 'finished');

        // Check meta counts
        $res = $this->getJson('/api/v1/wishlist')->assertOk();
        $res->assertJsonPath('meta.counts.all', 3);
        $res->assertJsonPath('meta.counts.want_to_read', 1);
        $res->assertJsonPath('meta.counts.reading', 1);
        $res->assertJsonPath('meta.counts.finished', 1);

        // Filter by reading status
        $readingRes = $this->getJson('/api/v1/wishlist?status=reading')->assertOk();
        $readingRes->assertJsonCount(1, 'data');
        $readingRes->assertJsonPath('data.0.id', $book2->id);

        $finishedRes = $this->getJson('/api/v1/wishlist?status=finished')->assertOk();
        $finishedRes->assertJsonCount(1, 'data');
        $finishedRes->assertJsonPath('data.0.id', $book3->id);

        // Update book1 from want_to_read to reading
        $this->patchJson("/api/v1/wishlist/{$book1->id}", ['status' => 'reading'])
            ->assertOk()
            ->assertJsonPath('status', 'reading');

        $this->getJson('/api/v1/wishlist?status=reading')->assertJsonCount(2, 'data');

        // Cannot update book not in wishlist
        $this->patchJson('/api/v1/wishlist/99999', ['status' => 'reading'])
            ->assertNotFound();

        // Cannot update with invalid status
        $this->patchJson("/api/v1/wishlist/{$book1->id}", ['status' => 'invalid_status'])
            ->assertUnprocessable();
    }

    public function test_unavailable_books_stay_listed_and_deleted_books_disappear(): void
    {
        $book = BookVariant::factory()->create()->book;
        $this->postJson('/api/v1/wishlist', ['book_id' => $book->id])->assertCreated();

        $book->variants()->update(['is_active' => false]);
        $this->getJson('/api/v1/wishlist')->assertJsonPath('data.0.in_stock', false)->assertJsonPath('data.0.price_from_usd', null);

        $book->delete();
        $this->getJson('/api/v1/wishlist')->assertJsonCount(0, 'data');
    }

    public function test_cannot_add_missing_or_deleted_books(): void
    {
        $deleted = Book::factory()->create();
        $deleted->delete();

        $this->postJson('/api/v1/wishlist', ['book_id' => 999])->assertUnprocessable();
        $this->postJson('/api/v1/wishlist', ['book_id' => $deleted->id])->assertUnprocessable();
    }

    public function test_wishlists_are_private(): void
    {
        $other = Customer::factory()->create();
        $other->wishlistBooks()->attach(BookVariant::factory()->create()->book_id, ['created_at' => now(), 'status' => 'want_to_read']);

        $this->getJson('/api/v1/wishlist')->assertJsonCount(0, 'data');
    }
}

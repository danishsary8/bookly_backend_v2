<?php

namespace Tests\Feature\Shopping;

use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Customer;
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

        $this->postJson('/api/v1/wishlist', ['book_id' => $older->id])->assertCreated();
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/wishlist', ['book_id' => $newer->id])->assertCreated();
        $this->postJson('/api/v1/wishlist', ['book_id' => $newer->id])->assertOk()->assertJsonPath('message', 'Already in your wishlist.');

        $this->getJson('/api/v1/wishlist')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonStructure(['data' => [['id', 'title', 'price_from_usd', 'in_stock']], 'meta']);

        $this->deleteJson("/api/v1/wishlist/{$older->id}")->assertNoContent();
        $this->getJson('/api/v1/wishlist')->assertJsonCount(1, 'data');
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
        $other->wishlistBooks()->attach(BookVariant::factory()->create()->book_id, ['created_at' => now()]);

        $this->getJson('/api/v1/wishlist')->assertJsonCount(0, 'data');
    }
}

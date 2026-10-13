<?php

namespace Tests\Feature\Orders;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class OrderLineSnapshotTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private BookVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $book = Book::factory()->create(['title' => 'Emma']);
        $book->authors()->attach([Author::factory()->create(['name' => 'Jane Austen'])->id, Author::factory()->create(['name' => 'Fiona Stafford'])->id]);
        $this->variant = BookVariant::factory()->for($book)->create(['sku' => 'EMMA-PB', 'isbn' => '9780141439587', 'stock_quantity' => 10]);
    }

    /** @return array{0: int, 1: string} order id and the customer's token */
    private function placeOrder(): array
    {
        $customer = Customer::factory()->create();
        $address = CustomerAddress::factory()->for($customer)->create();
        $token = $customer->createToken('t', ['customer'])->plainTextToken;
        $this->asToken($token)->postJson('/api/v1/cart/items', ['book_variant_id' => $this->variant->id, 'quantity' => 1])->assertCreated();
        $id = $this->withHeader('Idempotency-Key', 'snapshot-key-'.$customer->id)->postJson('/api/v1/checkout', [
            'address_id' => $address->id, 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');

        return [$id, $token];
    }

    public function test_an_order_line_keeps_the_book_details_it_was_bought_with(): void
    {
        [$id, $token] = $this->placeOrder();
        $line = OrderItem::first();
        $this->assertSame(['Emma', 'paperback', 'EMMA-PB', '9780141439587', ['Jane Austen', 'Fiona Stafford']], [$line->title, $line->format->value, $line->sku, $line->isbn, $line->authors]);

        // The shop renames the book, swaps an author and re-labels the format; then the book is deleted.
        $this->variant->book->update(['title' => 'Emma (Penguin Classics)']);
        $this->variant->book->authors()->detach(Author::where('name', 'Fiona Stafford')->first());
        $this->variant->update(['sku' => 'EMMA-PB-2', 'isbn' => '9780141199535']);
        $this->variant->book->delete();
        $staff = $this->staffToken();

        $this->asToken($token)->getJson("/api/v1/orders/{$id}")->assertOk()
            ->assertJsonPath('data.items.0.title', 'Emma')
            ->assertJsonPath('data.items.0.format', 'paperback');
        $this->asToken($token)->getJson("/api/v1/orders/{$id}/invoice")->assertOk()
            ->assertJsonPath('data.items.0.title', 'Emma')
            ->assertJsonPath('data.items.0.sku', 'EMMA-PB')
            ->assertJsonPath('data.items.0.isbn', '9780141439587')
            ->assertJsonPath('data.items.0.authors', ['Jane Austen', 'Fiona Stafford']);
        $this->asToken($staff)->getJson("/api/v1/staff/orders/{$id}")->assertOk()->assertJsonPath('data.items.0.title', 'Emma');
        $this->asToken($staff)->getJson('/api/v1/staff/orders/documents?ids[]='.$id)->assertOk()
            ->assertJsonPath('data.0.items.0.title', 'Emma')
            ->assertJsonPath('data.0.items.0.sku', 'EMMA-PB');
    }

    public function test_the_migration_fills_lines_written_before_it_from_the_book_including_deleted_ones(): void
    {
        $item = OrderItem::factory()->create(['book_variant_id' => $this->variant->id]);
        $empty = OrderItem::factory()->create(); // a book without authors
        $this->variant->book->delete();
        $this->assertNull($item->fresh()->title);

        $migration = require database_path('migrations/2026_10_13_200001_add_book_details_to_order_items.php');
        $migration->down();
        $migration->up();

        $item = $item->fresh();
        $this->assertSame(['Emma', 'paperback', 'EMMA-PB', '9780141439587', ['Jane Austen', 'Fiona Stafford']], [$item->title, $item->format->value, $item->sku, $item->isbn, $item->authors]);
        $this->assertSame([], $empty->fresh()->authors);
    }

    public function test_a_line_without_saved_details_falls_back_to_the_live_book(): void
    {
        $item = OrderItem::factory()->create(['book_variant_id' => $this->variant->id]);

        $this->assertSame(['Emma', 'paperback', 'EMMA-PB', '9780141439587', ['Jane Austen', 'Fiona Stafford']], [$item->bookTitle(), $item->bookFormat()->value, $item->bookSku(), $item->bookIsbn(), $item->bookAuthors()]);
    }
}

<?php

namespace Tests\Feature\Catalog;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class SeriesVolumesTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private function createCustomer(): Customer
    {
        return Customer::factory()->create([
            'email_verified_at' => now(),
        ]);
    }

    private function createBookInSeries(Series $series, ?int $order, float $price = 10.0, int $stock = 5, BookFormat $format = BookFormat::Paperback): Book
    {
        $book = Book::factory()->create([
            'status' => 'live',
            'series_id' => $series->id,
            'series_order' => $order,
        ]);

        BookVariant::factory()->create([
            'book_id' => $book->id,
            'format' => $format,
            'price_usd' => $price,
            'stock_quantity' => $stock,
            'is_active' => true,
        ]);

        return $book;
    }

    public function test_series_books_are_returned_in_order_with_formats_price_and_stock(): void
    {
        $series = Series::factory()->create(['name' => 'Frieren: Beyond Journey\'s End']);

        $vol2 = $this->createBookInSeries($series, 2, 12.00, 3);
        $vol1 = $this->createBookInSeries($series, 1, 9.99, 10);
        $vol3 = $this->createBookInSeries($series, 3, 11.50, 0);

        $response = $this->getJson("/api/v1/series/{$series->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Frieren: Beyond Journey\'s End')
            ->assertJsonPath('data.books_count', 3)
            ->assertJsonPath('data.total_volumes', 3);

        $books = $response->json('data.books');
        $this->assertCount(3, $books);

        // Assert strictly sorted by series_order asc
        $this->assertEquals($vol1->id, $books[0]['id']);
        $this->assertEquals(1, $books[0]['series_order']);
        $this->assertEquals('9.99', $books[0]['price_from_usd']);
        $this->assertTrue($books[0]['in_stock']);

        $this->assertEquals($vol2->id, $books[1]['id']);
        $this->assertEquals(2, $books[1]['series_order']);
        $this->assertTrue($books[1]['in_stock']);

        $this->assertEquals($vol3->id, $books[2]['id']);
        $this->assertEquals(3, $books[2]['series_order']);
        $this->assertFalse($books[2]['in_stock']);
    }

    public function test_owned_and_in_cart_flags_are_returned_only_for_signed_in_customer(): void
    {
        $series = Series::factory()->create(['name' => 'Fullmetal Alchemist']);
        $vol1 = $this->createBookInSeries($series, 1, 10.00, 5);
        $vol2 = $this->createBookInSeries($series, 2, 10.00, 5);
        $vol3 = $this->createBookInSeries($series, 3, 10.00, 5);

        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();

        // Customer A owns Volume 1 via delivered order
        $order = Order::factory()->create([
            'customer_id' => $customerA->id,
            'status' => OrderStatus::Delivered->value,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'book_variant_id' => $vol1->variants->first()->id,
            'quantity' => 1,
        ]);

        // Customer A has Volume 2 in cart
        $cartA = Cart::firstOrCreate(['customer_id' => $customerA->id]);
        $cartA->items()->create([
            'book_variant_id' => $vol2->variants->first()->id,
            'quantity' => 1,
            'unit_price_at_add' => 10.00,
        ]);

        // 1. Guest request: flags are false
        $guestRes = $this->getJson("/api/v1/series/{$series->id}")
            ->assertOk();
        $guestBooks = $guestRes->json('data.books');
        $this->assertFalse($guestBooks[0]['is_owned']);
        $this->assertFalse($guestBooks[0]['is_in_cart']);
        $this->assertFalse($guestBooks[1]['is_owned']);
        $this->assertFalse($guestBooks[1]['is_in_cart']);

        // 2. Customer B request: flags are false
        Sanctum::actingAs($customerB, ['customer']);
        $custBRes = $this->getJson("/api/v1/series/{$series->id}")
            ->assertOk();
        $custBBooks = $custBRes->json('data.books');
        $this->assertFalse($custBBooks[0]['is_owned']);
        $this->assertFalse($custBBooks[0]['is_in_cart']);

        // 3. Customer A request: Volume 1 is owned, Volume 2 is in cart, Volume 3 is neither
        Sanctum::actingAs($customerA, ['customer']);
        $custARes = $this->getJson("/api/v1/series/{$series->id}")
            ->assertOk()
            ->assertJsonPath('data.owned_count', 1)
            ->assertJsonPath('data.in_cart_count', 1);

        $custABooks = $custARes->json('data.books');
        // Vol 1: Owned
        $this->assertTrue($custABooks[0]['is_owned']);
        $this->assertFalse($custABooks[0]['is_in_cart']);

        // Vol 2: In Cart
        $this->assertFalse($custABooks[1]['is_owned']);
        $this->assertTrue($custABooks[1]['is_in_cart']);

        // Vol 3: Neither
        $this->assertFalse($custABooks[2]['is_owned']);
        $this->assertFalse($custABooks[2]['is_in_cart']);
    }

    public function test_complete_the_set_adds_missing_volumes_and_skips_owned_in_cart_and_out_of_stock(): void
    {
        $series = Series::factory()->create(['name' => 'Spy x Family']);
        $vol1 = $this->createBookInSeries($series, 1, 8.00, 10);
        $vol2 = $this->createBookInSeries($series, 2, 8.00, 10);
        $vol3 = $this->createBookInSeries($series, 3, 8.00, 10);
        $vol4 = $this->createBookInSeries($series, 4, 8.00, 0); // Out of stock

        $customer = $this->createCustomer();

        // Customer already owns Vol 1
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'status' => OrderStatus::Paid->value,
        ]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'book_variant_id' => $vol1->variants->first()->id,
            'quantity' => 1,
        ]);

        // Customer already has Vol 2 in cart
        $cart = Cart::firstOrCreate(['customer_id' => $customer->id]);
        $cart->items()->create([
            'book_variant_id' => $vol2->variants->first()->id,
            'quantity' => 1,
            'unit_price_at_add' => 8.00,
        ]);

        Sanctum::actingAs($customer, ['customer']);

        // Call POST /cart/series/{id}
        $response = $this->postJson("/api/v1/cart/series/{$series->id}")
            ->assertOk()
            ->assertJsonPath('data.added_count', 1)
            ->assertJsonPath('data.skipped_count', 1);

        // Vol 3 was added
        $added = $response->json('data.added');
        $this->assertCount(1, $added);
        $this->assertEquals($vol3->id, $added[0]['book_id']);
        $this->assertEquals(3, $added[0]['series_order']);

        // Vol 4 was skipped (out of stock)
        $skipped = $response->json('data.skipped');
        $this->assertCount(1, $skipped);
        $this->assertEquals($vol4->id, $skipped[0]['book_id']);
        $this->assertEquals('out_of_stock', $skipped[0]['reason']);

        // Vol 1 (owned) and Vol 2 (in cart) were not re-added
        $cartItems = $cart->fresh()->items;
        $this->assertCount(2, $cartItems); // Was 1 item (Vol 2), now 2 items (Vol 2 and Vol 3)
        $this->assertTrue($cartItems->contains('book_variant_id', $vol3->variants->first()->id));
    }

    public function test_next_volume_returns_next_book_in_sequence(): void
    {
        $series = Series::factory()->create();
        $vol1 = $this->createBookInSeries($series, 1);
        $vol2 = $this->createBookInSeries($series, 2);
        $vol3 = $this->createBookInSeries($series, 3);

        // Vol 1 -> next is Vol 2
        $this->getJson("/api/v1/books/{$vol1->id}/next-volume")
            ->assertOk()
            ->assertJsonPath('data.id', $vol2->id)
            ->assertJsonPath('data.series_order', 2);

        // Vol 2 -> next is Vol 3
        $this->getJson("/api/v1/books/{$vol2->id}/next-volume")
            ->assertOk()
            ->assertJsonPath('data.id', $vol3->id)
            ->assertJsonPath('data.series_order', 3);

        // Vol 3 -> no next volume
        $this->getJson("/api/v1/books/{$vol3->id}/next-volume")
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_staff_can_view_and_update_series_volume_orders_with_duplicate_warning(): void
    {
        $token = $this->staffToken();
        $series = Series::factory()->create(['name' => 'Attack on Titan']);
        $bookA = $this->createBookInSeries($series, 1);
        $bookB = $this->createBookInSeries($series, 2);

        // Staff views series volumes
        $this->asToken($token)->getJson("/api/v1/staff/series/{$series->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Attack on Titan')
            ->assertJsonCount(2, 'data.books');

        // Staff updates volume order: set both to order 1 (creates duplicate)
        $updateRes = $this->asToken($token)->putJson("/api/v1/staff/series/{$series->id}/volumes", [
            'volumes' => [
                ['id' => $bookA->id, 'series_order' => 1],
                ['id' => $bookB->id, 'series_order' => 1],
            ],
        ])->assertOk();

        // Duplicate warning returned
        $warnings = $updateRes->json('data.warnings');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Duplicate volume number 1', $warnings[0]);

        $this->assertEquals(1, $bookA->fresh()->series_order);
        $this->assertEquals(1, $bookB->fresh()->series_order);

        // Now fix the order cleanly: A -> 2, B -> 1
        $fixedRes = $this->asToken($token)->putJson("/api/v1/staff/series/{$series->id}/volumes", [
            'volumes' => [
                ['id' => $bookA->id, 'series_order' => 2],
                ['id' => $bookB->id, 'series_order' => 1],
            ],
        ])->assertOk();

        $this->assertEmpty($fixedRes->json('data.warnings'));
        $this->assertEquals(2, $bookA->fresh()->series_order);
        $this->assertEquals(1, $bookB->fresh()->series_order);
    }
}

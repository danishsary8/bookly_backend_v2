<?php

namespace Tests\Feature\Platform;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AlsoBoughtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_books_ids_filter_preserves_order_and_drops_missing_or_draft_books(): void
    {
        $b1 = Book::factory()->create(['title' => 'Book 1', 'status' => 'live']);
        BookVariant::factory()->create(['book_id' => $b1->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        $b2 = Book::factory()->create(['title' => 'Book 2 (Draft)', 'status' => 'draft']);
        BookVariant::factory()->create(['book_id' => $b2->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        $b3 = Book::factory()->create(['title' => 'Book 3', 'status' => 'live']);
        BookVariant::factory()->create(['book_id' => $b3->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        $b4 = Book::factory()->create(['title' => 'Book 4', 'status' => 'live']);
        BookVariant::factory()->create(['book_id' => $b4->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        // Request with custom order: 4, 1, 99999, 2, 3
        $res = $this->getJson("/api/v1/books?ids={$b4->id},{$b1->id},99999,{$b2->id},{$b3->id}")
            ->assertOk();

        $res->assertJsonCount(3, 'data');
        $res->assertJsonPath('data.0.id', $b4->id);
        $res->assertJsonPath('data.1.id', $b1->id);
        $res->assertJsonPath('data.2.id', $b3->id);
    }

    public function test_also_bought_requires_two_or_more_shared_orders_excluding_pending_and_cancelled(): void
    {
        $customer = Customer::factory()->create();

        // Target book T
        $target = Book::factory()->create(['title' => 'Target Book', 'status' => 'live']);
        $varT = BookVariant::factory()->create(['book_id' => $target->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        // Book B: 2 shared completed orders
        $bookB = Book::factory()->create(['title' => 'Book B', 'status' => 'live']);
        $varB = BookVariant::factory()->create(['book_id' => $bookB->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        // Book C: 1 shared order only
        $bookC = Book::factory()->create(['title' => 'Book C', 'status' => 'live']);
        $varC = BookVariant::factory()->create(['book_id' => $bookC->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        // Book D: 2 shared orders but cancelled and pending
        $bookD = Book::factory()->create(['title' => 'Book D', 'status' => 'live']);
        $varD = BookVariant::factory()->create(['book_id' => $bookD->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        // Order 1: Delivered (T and B)
        $o1 = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered]);
        OrderItem::factory()->create(['order_id' => $o1->id, 'book_variant_id' => $varT->id]);
        OrderItem::factory()->create(['order_id' => $o1->id, 'book_variant_id' => $varB->id]);

        // Order 2: Processing (T and B)
        $o2 = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Processing]);
        OrderItem::factory()->create(['order_id' => $o2->id, 'book_variant_id' => $varT->id]);
        OrderItem::factory()->create(['order_id' => $o2->id, 'book_variant_id' => $varB->id]);

        // Order 3: Delivered (T and C) - only 1 shared order
        $o3 = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Delivered]);
        OrderItem::factory()->create(['order_id' => $o3->id, 'book_variant_id' => $varT->id]);
        OrderItem::factory()->create(['order_id' => $o3->id, 'book_variant_id' => $varC->id]);

        // Order 4: Cancelled (T and D)
        $o4 = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Cancelled]);
        OrderItem::factory()->create(['order_id' => $o4->id, 'book_variant_id' => $varT->id]);
        OrderItem::factory()->create(['order_id' => $o4->id, 'book_variant_id' => $varD->id]);

        // Order 5: Pending (T and D)
        $o5 = Order::factory()->create(['customer_id' => $customer->id, 'status' => OrderStatus::Pending]);
        OrderItem::factory()->create(['order_id' => $o5->id, 'book_variant_id' => $varT->id]);
        OrderItem::factory()->create(['order_id' => $o5->id, 'book_variant_id' => $varD->id]);

        $res = $this->getJson("/api/v1/books/{$target->id}/also-bought")
            ->assertOk();

        // B is the only real "bought together" book (2 paid orders), so it comes first. C and D are only store
        // best-seller fillers: C sold 1 paid copy, D none (its orders were cancelled or pending), so D is last.
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertSame([$bookB->id, $bookC->id, $bookD->id], $ids);
        $this->assertNotContains($target->id, $ids);
    }

    public function test_also_bought_falls_back_to_category_best_sellers_if_under_three(): void
    {
        $category = Category::factory()->create(['name' => 'Fiction', 'slug' => 'fiction']);

        $target = Book::factory()->create(['title' => 'Target', 'status' => 'live']);
        $target->categories()->attach($category->id);
        BookVariant::factory()->create(['book_id' => $target->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        // Category sibling 1
        $sib1 = Book::factory()->create(['title' => 'Sibling 1', 'status' => 'live']);
        $sib1->categories()->attach($category->id);
        BookVariant::factory()->create(['book_id' => $sib1->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        // Category sibling 2
        $sib2 = Book::factory()->create(['title' => 'Sibling 2', 'status' => 'live']);
        $sib2->categories()->attach($category->id);
        BookVariant::factory()->create(['book_id' => $sib2->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 10]);

        // Category sibling 3 (out of stock physical) -> should be excluded
        $sibOut = Book::factory()->create(['title' => 'Sibling Out', 'status' => 'live']);
        $sibOut->categories()->attach($category->id);
        BookVariant::factory()->create(['book_id' => $sibOut->id, 'format' => BookFormat::Paperback, 'is_active' => true, 'stock_quantity' => 0]);

        // With zero co-occurring orders, fallback provides category best sellers (excluding out-of-stock and target)
        $res = $this->getJson("/api/v1/books/{$target->id}/also-bought")
            ->assertOk();

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($sib1->id, $ids);
        $this->assertContains($sib2->id, $ids);
        $this->assertNotContains($sibOut->id, $ids);
        $this->assertNotContains($target->id, $ids);
    }
}

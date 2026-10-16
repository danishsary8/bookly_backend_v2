<?php

namespace Tests\Feature\Catalog;

use App\Enums\BookFormat;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\InventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class StaffStockTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_guest_and_customer_cannot_access_stock_endpoints(): void
    {
        $book = Book::factory()->create();
        $variant = BookVariant::factory()->create(['book_id' => $book->id, 'format' => BookFormat::Paperback]);

        $this->getJson('/api/v1/staff/stock')->assertUnauthorized();
        $this->postJson("/api/v1/staff/variants/{$variant->id}/restock", ['quantity' => 10])->assertUnauthorized();
        $this->getJson("/api/v1/staff/variants/{$variant->id}/stock-history")->assertUnauthorized();

        $customer = Customer::factory()->create();
        $customerToken = $customer->createToken('test', ['customer'])->plainTextToken;

        $this->withToken($customerToken)->getJson('/api/v1/staff/stock')->assertForbidden();
        $this->withToken($customerToken)->postJson("/api/v1/staff/variants/{$variant->id}/restock", ['quantity' => 10])->assertForbidden();
        $this->withToken($customerToken)->getJson("/api/v1/staff/variants/{$variant->id}/stock-history")->assertForbidden();
    }

    public function test_restock_happy_path_records_movement_updates_stock_and_logs_audit(): void
    {
        $token = $this->staffToken();
        $book = Book::factory()->create();
        $variant = BookVariant::factory()->create([
            'book_id' => $book->id,
            'format' => BookFormat::Paperback,
            'stock_quantity' => 5,
        ]);

        $res = $this->asToken($token)->postJson("/api/v1/staff/variants/{$variant->id}/restock", [
            'quantity' => 20,
            'supplier' => 'Phnom Penh Distributors',
            'unit_cost_usd' => '4.50',
            'note' => 'Weekly replenishment',
        ])->assertOk();

        $res->assertJsonPath('variant.stock_quantity', 25);
        $res->assertJsonPath('movement.change_qty', 20);
        $res->assertJsonPath('movement.supplier', 'Phnom Penh Distributors');
        $res->assertJsonPath('movement.note', 'Weekly replenishment');

        $this->assertSame(25, $variant->fresh()->stock_quantity);

        $movement = InventoryMovement::where('book_variant_id', $variant->id)->latest('id')->first();
        $this->assertNotNull($movement);
        $this->assertSame(20, $movement->change_qty);
        $this->assertSame('restock', $movement->reason->value);
        $this->assertSame('Phnom Penh Distributors', $movement->supplier);
        $this->assertSame('4.50', (string) $movement->unit_cost_usd);
        $this->assertSame('Weekly replenishment', $movement->note);

        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'book_variant.restocked',
            'entity_id' => $variant->id,
        ]);
    }

    public function test_restock_validations_and_bounds(): void
    {
        $token = $this->staffToken();
        $book = Book::factory()->create();
        $variant = BookVariant::factory()->create(['book_id' => $book->id, 'format' => BookFormat::Paperback]);

        // Quantity bounds: 1 to 10000
        $this->asToken($token)->postJson("/api/v1/staff/variants/{$variant->id}/restock", [
            'quantity' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->asToken($token)->postJson("/api/v1/staff/variants/{$variant->id}/restock", [
            'quantity' => 10001,
        ])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        // Supplier max 150
        $this->asToken($token)->postJson("/api/v1/staff/variants/{$variant->id}/restock", [
            'quantity' => 5,
            'supplier' => str_repeat('a', 151),
        ])->assertUnprocessable()->assertJsonValidationErrors('supplier');

        // Unit cost bounds 0 to 99999.99 with max 2 decimals
        $this->asToken($token)->postJson("/api/v1/staff/variants/{$variant->id}/restock", [
            'quantity' => 5,
            'unit_cost_usd' => '-1.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('unit_cost_usd');

        $this->asToken($token)->postJson("/api/v1/staff/variants/{$variant->id}/restock", [
            'quantity' => 5,
            'unit_cost_usd' => '100000.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('unit_cost_usd');

        $this->asToken($token)->postJson("/api/v1/staff/variants/{$variant->id}/restock", [
            'quantity' => 5,
            'unit_cost_usd' => '4.999',
        ])->assertUnprocessable()->assertJsonValidationErrors('unit_cost_usd');

        // Note max 500
        $this->asToken($token)->postJson("/api/v1/staff/variants/{$variant->id}/restock", [
            'quantity' => 5,
            'note' => str_repeat('a', 501),
        ])->assertUnprocessable()->assertJsonValidationErrors('note');
    }

    public function test_digital_format_cannot_be_restocked(): void
    {
        $token = $this->staffToken();
        $book = Book::factory()->create();
        $ebook = BookVariant::factory()->create(['book_id' => $book->id, 'format' => BookFormat::Ebook]);
        $audiobook = BookVariant::factory()->create(['book_id' => $book->id, 'format' => BookFormat::Audiobook]);

        $this->asToken($token)->postJson("/api/v1/staff/variants/{$ebook->id}/restock", [
            'quantity' => 10,
        ])->assertStatus(422)->assertJson(['message' => 'Digital formats have no stock.']);

        $this->asToken($token)->postJson("/api/v1/staff/variants/{$audiobook->id}/restock", [
            'quantity' => 10,
        ])->assertStatus(422)->assertJson(['message' => 'Digital formats have no stock.']);
    }

    public function test_staff_cannot_see_unit_cost_usd_in_stock_history_admin_can(): void
    {
        $staffToken = $this->staffToken(admin: false);
        $adminToken = $this->staffToken(admin: true);

        $book = Book::factory()->create();
        $variant = BookVariant::factory()->create(['book_id' => $book->id, 'format' => BookFormat::Paperback]);

        $this->asToken($adminToken)->postJson("/api/v1/staff/variants/{$variant->id}/restock", [
            'quantity' => 15,
            'supplier' => 'Direct Press',
            'unit_cost_usd' => '3.75',
        ])->assertOk();

        // Staff query
        $staffRes = $this->asToken($staffToken)->getJson("/api/v1/staff/variants/{$variant->id}/stock-history")->assertOk();
        $staffRow = $staffRes->json('data.0');
        $this->assertSame(15, $staffRow['change_qty']);
        $this->assertSame('Direct Press', $staffRow['supplier']);
        $this->assertArrayNotHasKey('unit_cost_usd', $staffRow);

        // Admin query
        $adminRes = $this->asToken($adminToken)->getJson("/api/v1/staff/variants/{$variant->id}/stock-history")->assertOk();
        $adminRow = $adminRes->json('data.0');
        $this->assertArrayHasKey('unit_cost_usd', $adminRow);
        $this->assertSame('3.75', $adminRow['unit_cost_usd']);
    }

    public function test_running_balance_computed_correctly_across_pages(): void
    {
        $adminToken = $this->staffToken(admin: true);
        $book = Book::factory()->create();
        $variant = BookVariant::factory()->create([
            'book_id' => $book->id,
            'format' => BookFormat::Hardcover,
            'stock_quantity' => 0,
        ]);

        // Add 25 movements
        // Each movement adds 2 copies
        for ($i = 1; $i <= 25; $i++) {
            $this->asToken($adminToken)->postJson("/api/v1/staff/variants/{$variant->id}/restock", [
                'quantity' => 2,
                'note' => "Batch {$i}",
            ])->assertOk();
        }

        $this->assertSame(50, $variant->fresh()->stock_quantity);

        // Page 1 (20 items, newest first: batch 25 down to batch 6)
        $page1 = $this->asToken($adminToken)->getJson("/api/v1/staff/variants/{$variant->id}/stock-history?per_page=20&page=1")->assertOk();
        $this->assertCount(20, $page1->json('data'));
        // Newest item is Batch 25 -> stock_after must be 50
        $this->assertSame('Batch 25', $page1->json('data.0.note'));
        $this->assertSame(50, $page1->json('data.0.stock_after'));
        // 20th item on page 1 is Batch 6 -> stock_after must be 12 (6 * 2)
        $this->assertSame('Batch 6', $page1->json('data.19.note'));
        $this->assertSame(12, $page1->json('data.19.stock_after'));

        // Page 2 (5 items: batch 5 down to batch 1)
        $page2 = $this->asToken($adminToken)->getJson("/api/v1/staff/variants/{$variant->id}/stock-history?per_page=20&page=2")->assertOk();
        $this->assertCount(5, $page2->json('data'));
        // 1st item on page 2 is Batch 5 -> stock_after must be 10 (5 * 2)
        $this->assertSame('Batch 5', $page2->json('data.0.note'));
        $this->assertSame(10, $page2->json('data.0.stock_after'));
        // Oldest item on page 2 is Batch 1 -> stock_after must be 2 (1 * 2)
        $this->assertSame('Batch 1', $page2->json('data.4.note'));
        $this->assertSame(2, $page2->json('data.4.stock_after'));
    }

    public function test_stock_list_filters_and_sorting(): void
    {
        $token = $this->staffToken();

        $bookA = Book::factory()->create(['title' => 'Alpha Book']);
        $bookB = Book::factory()->create(['title' => 'Beta Book']);
        $bookC = Book::factory()->create(['title' => 'Gamma Book']);

        // Out of stock
        $v1 = BookVariant::factory()->create([
            'book_id' => $bookA->id,
            'format' => BookFormat::Paperback,
            'stock_quantity' => 0,
            'low_stock_threshold' => 5,
            'sku' => 'ALPHA-PB',
        ]);

        // Low stock
        $v2 = BookVariant::factory()->create([
            'book_id' => $bookB->id,
            'format' => BookFormat::Hardcover,
            'stock_quantity' => 3,
            'low_stock_threshold' => 5,
            'sku' => 'BETA-HC',
        ]);

        // In stock
        $v3 = BookVariant::factory()->create([
            'book_id' => $bookC->id,
            'format' => BookFormat::Paperback,
            'stock_quantity' => 25,
            'low_stock_threshold' => 5,
            'sku' => 'GAMMA-PB',
        ]);

        // Digital format should NOT appear in staff stock list
        BookVariant::factory()->create([
            'book_id' => $bookA->id,
            'format' => BookFormat::Ebook,
            'stock_quantity' => 0,
            'sku' => 'ALPHA-EB',
        ]);

        // Default list: sorted by stock ascending (v1: 0, v2: 3, v3: 25)
        $resAll = $this->asToken($token)->getJson('/api/v1/staff/stock')->assertOk();
        $this->assertCount(3, $resAll->json('data'));
        $this->assertSame($v1->id, $resAll->json('data.0.id'));
        $this->assertSame('out', $resAll->json('data.0.status'));
        $this->assertSame($v2->id, $resAll->json('data.1.id'));
        $this->assertSame('low', $resAll->json('data.1.status'));
        $this->assertSame($v3->id, $resAll->json('data.2.id'));
        $this->assertSame('in_stock', $resAll->json('data.2.status'));

        // Filter status=out
        $resOut = $this->asToken($token)->getJson('/api/v1/staff/stock?status=out')->assertOk();
        $this->assertCount(1, $resOut->json('data'));
        $this->assertSame($v1->id, $resOut->json('data.0.id'));

        // Filter status=low
        $resLow = $this->asToken($token)->getJson('/api/v1/staff/stock?status=low')->assertOk();
        $this->assertCount(1, $resLow->json('data'));
        $this->assertSame($v2->id, $resLow->json('data.0.id'));

        // Search q=Beta
        $resQ = $this->asToken($token)->getJson('/api/v1/staff/stock?q=Beta')->assertOk();
        $this->assertCount(1, $resQ->json('data'));
        $this->assertSame($v2->id, $resQ->json('data.0.id'));

        // Search q=ALPHA-PB
        $resSku = $this->asToken($token)->getJson('/api/v1/staff/stock?q=ALPHA-PB')->assertOk();
        $this->assertCount(1, $resSku->json('data'));
        $this->assertSame($v1->id, $resSku->json('data.0.id'));
    }

    public function test_stock_list_checks_its_filters(): void
    {
        $token = $this->staffToken();
        $this->asToken($token)->getJson('/api/v1/staff/stock?status=nope')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->asToken($token)->getJson('/api/v1/staff/stock?per_page=500')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }
}

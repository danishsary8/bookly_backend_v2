<?php

namespace Tests\Feature\Platform;

use App\Enums\BookFormat;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\MarketingOutbox;
use App\Models\StaffUser;
use App\Models\StockAlert;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_or_manage_stock_alerts(): void
    {
        $this->getJson('/api/v1/me/stock-alerts')->assertUnauthorized();
        $this->postJson('/api/v1/me/stock-alerts', ['book_variant_id' => 1])->assertUnauthorized();
        $this->deleteJson('/api/v1/me/stock-alerts/1')->assertUnauthorized();
    }

    public function test_customer_can_list_and_delete_stock_alerts(): void
    {
        $customer = Customer::factory()->create();
        $book = Book::factory()->create(['status' => 'live']);
        $variant = BookVariant::factory()->create([
            'book_id' => $book->id,
            'format' => BookFormat::Paperback,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $alert = StockAlert::create([
            'customer_id' => $customer->id,
            'book_variant_id' => $variant->id,
            'created_at' => now(),
        ]);

        $res = Sanctum::actingAs($customer, ['customer']);
        $res = $this->getJson('/api/v1/me/stock-alerts')
            ->assertOk();

        $res->assertJsonCount(1, 'data');
        $res->assertJsonPath('data.0.id', $alert->id);
        $res->assertJsonPath('data.0.book.title', $book->title);
        $res->assertJsonPath('data.0.variant.format', 'paperback');

        // Delete alert
        $this->deleteJson("/api/v1/me/stock-alerts/{$alert->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('stock_alerts', ['id' => $alert->id]);
    }

    public function test_customer_cannot_delete_another_customers_stock_alert(): void
    {
        $alice = Customer::factory()->create();
        $bob = Customer::factory()->create();

        $book = Book::factory()->create(['status' => 'live']);
        $variant = BookVariant::factory()->create([
            'book_id' => $book->id,
            'format' => BookFormat::Paperback,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $alert = StockAlert::create([
            'customer_id' => $alice->id,
            'book_variant_id' => $variant->id,
            'created_at' => now(),
        ]);

        Sanctum::actingAs($bob, ['customer']);
        $this->deleteJson("/api/v1/me/stock-alerts/{$alert->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('stock_alerts', ['id' => $alert->id]);
    }

    public function test_stock_alert_validations_and_rejections(): void
    {
        $customer = Customer::factory()->create();
        Sanctum::actingAs($customer, ['customer']);

        // 1. Digital format rejected
        $book = Book::factory()->create(['status' => 'live']);
        $ebook = BookVariant::factory()->create([
            'book_id' => $book->id,
            'format' => BookFormat::Ebook,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/me/stock-alerts', ['book_variant_id' => $ebook->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['book_variant_id']);

        // 2. In-stock format rejected
        $inStockVariant = BookVariant::factory()->create([
            'book_id' => $book->id,
            'format' => BookFormat::Paperback,
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/me/stock-alerts', ['book_variant_id' => $inStockVariant->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['book_variant_id']);

        // 3. Draft/hidden book rejected
        $draftBook = Book::factory()->create(['status' => 'draft']);
        $draftVariant = BookVariant::factory()->create([
            'book_id' => $draftBook->id,
            'format' => BookFormat::Paperback,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/me/stock-alerts', ['book_variant_id' => $draftVariant->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['book_variant_id']);

        // 4. Valid out-of-stock physical variant creates alert
        $outVariant = BookVariant::factory()->create([
            'book_id' => $book->id,
            'format' => BookFormat::Hardcover,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $createRes = $this->postJson('/api/v1/me/stock-alerts', ['book_variant_id' => $outVariant->id])
            ->assertCreated();

        $createRes->assertJsonPath('data.book_variant_id', $outVariant->id);

        // 5. Duplicate active alert rejected
        $this->postJson('/api/v1/me/stock-alerts', ['book_variant_id' => $outVariant->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['book_variant_id']);

        // 6. Max 50 active alerts
        for ($i = 1; $i <= 49; $i++) {
            $otherBook = Book::factory()->create(['status' => 'live']);
            $otherVar = BookVariant::factory()->create([
                'book_id' => $otherBook->id,
                'format' => BookFormat::Paperback,
                'stock_quantity' => 0,
                'is_active' => true,
            ]);
            StockAlert::create([
                'customer_id' => $customer->id,
                'book_variant_id' => $otherVar->id,
                'created_at' => now(),
            ]);
        }

        // Now has 50 active alerts. Trying to add 51st is rejected
        $extraBook = Book::factory()->create(['status' => 'live']);
        $extraVar = BookVariant::factory()->create([
            'book_id' => $extraBook->id,
            'format' => BookFormat::Paperback,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/me/stock-alerts', ['book_variant_id' => $extraVar->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['book_variant_id']);
    }

    public function test_restock_from_zero_triggers_marketing_outbox_once_and_marks_notified(): void
    {
        $customer = Customer::factory()->create(['locale' => 'km']);
        $staff = StaffUser::factory()->create(['role' => 'admin']);

        $book = Book::factory()->create(['title' => 'Khmer Tale', 'status' => 'live']);
        $variant = BookVariant::factory()->create([
            'book_id' => $book->id,
            'format' => BookFormat::Paperback,
            'stock_quantity' => 0,
            'is_active' => true,
        ]);

        $alert = StockAlert::create([
            'customer_id' => $customer->id,
            'book_variant_id' => $variant->id,
            'created_at' => now(),
            'notified_at' => null,
        ]);

        $this->assertSame(0, MarketingOutbox::count());

        // Restock variant from 0 to 10
        $service = app(InventoryService::class);
        $service->restock($variant, 10, $staff, 'Publisher Supply', 5.00, 'Arrival of new batch');

        // Outbox row created
        $this->assertSame(1, MarketingOutbox::count());
        $outbox = MarketingOutbox::first();
        $this->assertSame('back_in_stock', $outbox->kind);
        $this->assertSame($customer->id, $outbox->customer_id);
        $this->assertSame($customer->email, $outbox->email);
        $this->assertSame('km', $outbox->locale);
        $this->assertSame('Khmer Tale', $outbox->payload['book_title']);
        $this->assertSame('paperback', $outbox->payload['format']);
        $this->assertSame('pending', $outbox->status);

        // Alert notified_at set
        $alert->refresh();
        $this->assertNotNull($alert->notified_at);
    }

    public function test_restock_when_already_in_stock_does_not_trigger_marketing_outbox(): void
    {
        $customer = Customer::factory()->create();
        $staff = StaffUser::factory()->create(['role' => 'admin']);

        $book = Book::factory()->create(['status' => 'live']);
        $variant = BookVariant::factory()->create([
            'book_id' => $book->id,
            'format' => BookFormat::Paperback,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $service = app(InventoryService::class);
        $service->restock($variant, 10, $staff, 'Publisher Supply', 5.00);

        // No outbox row
        $this->assertSame(0, MarketingOutbox::count());
    }
}

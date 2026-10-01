<?php

namespace Tests\Feature\Catalog;

use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class StaffVariantManagementTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_creating_a_variant_logs_initial_stock_and_makes_book_public(): void
    {
        $token = $this->staffToken();
        $book = Book::factory()->create();

        $this->asToken($token)->postJson("/api/v1/staff/books/{$book->id}/variants", [
            'format' => 'paperback', 'sku' => 'EARTH-PB', 'price_usd' => '12.50', 'stock_quantity' => 40,
        ])->assertCreated()
            ->assertJsonPath('data.stock_quantity', 40)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.price_usd', '12.50');

        $movement = InventoryMovement::sole();
        $this->assertSame(40, $movement->change_qty);
        $this->assertSame('restock', $movement->reason->value);
        $this->assertNotNull($movement->created_by_staff_id);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'book_variant.created']);

        $this->getJson("/api/v1/books/{$book->id}")->assertOk()->assertJsonPath('data.formats.0', 'paperback');
    }

    public function test_validation_one_format_per_book_unique_sku_and_price_precision(): void
    {
        $token = $this->staffToken();
        $existing = BookVariant::factory()->create(['sku' => 'TAKEN']);

        $this->asToken($token)->postJson("/api/v1/staff/books/{$existing->book_id}/variants", [
            'format' => $existing->format->value, 'sku' => 'TAKEN', 'price_usd' => '1.999',
        ])->assertUnprocessable()->assertJsonValidationErrors(['format', 'sku', 'price_usd']);

        $this->asToken($token)->postJson("/api/v1/staff/books/{$existing->book_id}/variants", [
            'format' => 'vinyl', 'sku' => 'NEW', 'price_usd' => '-1',
        ])->assertUnprocessable()->assertJsonValidationErrors(['format', 'price_usd']);
    }

    public function test_stock_change_on_edit_is_logged_as_adjustment_and_price_change_is_audited(): void
    {
        $token = $this->staffToken();
        $variant = BookVariant::factory()->create(['stock_quantity' => 10, 'price_usd' => '10.00']);

        $this->asToken($token)->patchJson("/api/v1/staff/variants/{$variant->id}", ['stock_quantity' => 7, 'price_usd' => '12.00'])
            ->assertOk()->assertJsonPath('data.stock_quantity', 7);

        $movement = InventoryMovement::sole();
        $this->assertSame(-3, $movement->change_qty);
        $this->assertSame('adjustment', $movement->reason->value);

        $log = AdminAuditLog::where('action', 'book_variant.updated')->sole();
        $this->assertSame(['price_usd' => '10.00', 'stock_quantity' => 10], $log->before_data);
        $this->assertSame(['price_usd' => '12.00', 'stock_quantity' => 7], $log->after_data);

        // Same stock again: no new movement.
        $this->asToken($token)->patchJson("/api/v1/staff/variants/{$variant->id}", ['stock_quantity' => 7])->assertOk();
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_deactivated_variant_hides_from_public_catalog(): void
    {
        $variant = BookVariant::factory()->create();

        $this->asToken($this->staffToken())->patchJson("/api/v1/staff/variants/{$variant->id}", ['is_active' => false])->assertOk();

        $this->getJson("/api/v1/books/{$variant->book_id}")->assertNotFound();
    }

    public function test_delete_rules(): void
    {
        $staff = $this->staffToken();
        $admin = $this->staffToken(admin: true);
        $ordered = BookVariant::factory()->create();
        OrderItem::factory()->create(['book_variant_id' => $ordered->id]);
        $book = Book::factory()->create();
        $fresh = BookVariant::factory()->for($book)->create();
        $this->asToken($staff)->patchJson("/api/v1/staff/variants/{$fresh->id}", ['stock_quantity' => 3]);

        $this->asToken($staff)->deleteJson("/api/v1/staff/variants/{$fresh->id}")->assertForbidden();
        $this->asToken($admin)->deleteJson("/api/v1/staff/variants/{$ordered->id}")
            ->assertStatus(409)->assertJsonFragment(['message' => 'This format has been ordered before, so it cannot be deleted. Set is_active to false to hide it instead.']);
        $this->asToken($admin)->deleteJson("/api/v1/staff/variants/{$fresh->id}")->assertNoContent();

        $this->assertModelMissing($fresh);
        $this->assertModelExists($ordered);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'book_variant.deleted', 'entity_id' => $fresh->id]);
    }
}

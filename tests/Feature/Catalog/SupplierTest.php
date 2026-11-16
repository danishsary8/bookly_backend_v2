<?php

namespace Tests\Feature\Catalog;

use App\Enums\InventoryReason;
use App\Models\BookVariant;
use App\Models\InventoryMovement;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_staff_add_edit_and_switch_off_suppliers(): void
    {
        $token = $this->staffToken(admin: true);

        $id = $this->asToken($token)->postJson('/api/v1/staff/suppliers', ['name' => ' Monument Books ', 'phone' => '012 345 678'])
            ->assertCreated()->assertJsonPath('data.name', 'Monument Books')->json('data.id');
        $this->asToken($token)->postJson('/api/v1/staff/suppliers', ['name' => 'monument books'])->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->asToken($token)->patchJson("/api/v1/staff/suppliers/{$id}", ['is_active' => false, 'contact_name' => 'Dara'])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.contact_name', 'Dara');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'supplier.updated']);

        $this->asToken($token)->getJson('/api/v1/staff/suppliers?active=0')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->asToken($token)->getJson('/api/v1/staff/suppliers?q=xyz')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_restock_links_the_supplier_and_list_shows_spend(): void
    {
        $token = $this->staffToken(admin: true);
        $supplier = Supplier::create(['name' => 'Peace Book Center']);
        $variant = BookVariant::factory()->create(['stock_quantity' => 0]);

        $this->asToken($token)->postJson("/api/v1/staff/variants/{$variant->id}/restock", ['quantity' => 4, 'supplier_id' => $supplier->id, 'unit_cost_usd' => '5.50'])
            ->assertOk()->assertJsonPath('movement.supplier', 'Peace Book Center')->assertJsonPath('movement.supplier_id', $supplier->id);

        $this->asToken($token)->getJson('/api/v1/staff/suppliers')->assertOk()
            ->assertJsonPath('data.0.restocks', 1)->assertJsonPath('data.0.units', 4)->assertJsonPath('data.0.spend_usd', '22.00');

        $supplier->update(['is_active' => false]);
        $this->asToken($token)->postJson("/api/v1/staff/variants/{$variant->id}/restock", ['quantity' => 1, 'supplier_id' => $supplier->id])
            ->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    }

    public function test_migration_copies_typed_names_once(): void
    {
        $variant = BookVariant::factory()->create();
        foreach (['Monument', ' monument ', 'Other', null, ' '] as $name) {
            InventoryMovement::create(['book_variant_id' => $variant->id, 'change_qty' => 1, 'reason' => InventoryReason::Restock, 'supplier' => $name]);
        }

        $migration = require database_path('migrations/2026_11_16_000001_create_suppliers_table.php');
        $migration->down();
        $migration->up();

        $this->assertSame(2, Supplier::count());
        // "Monument" and " monument " are one supplier.
        $this->assertSame(1, InventoryMovement::where('supplier', 'ilike', '%monument%')->distinct()->count('supplier_id'));
        $this->assertSame(3, InventoryMovement::whereNotNull('supplier_id')->count());
    }
}

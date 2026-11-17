<?php

namespace Tests\Feature\Catalog;

use App\Models\Publisher;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** Publishers as suppliers (owner 2026-11-17): type publisher | distributor, a publisher links to its catalogue publisher. */
class SupplierTypeTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_publisher_and_distributor_suppliers(): void
    {
        $admin = $this->staffToken(admin: true);
        $publisher = Publisher::create(['name' => 'Penguin Random House']);

        $this->asToken($admin)->postJson('/api/v1/staff/suppliers', ['name' => 'Penguin direct', 'type' => 'publisher', 'publisher_id' => $publisher->id])
            ->assertCreated()->assertJsonPath('data.type', 'publisher')->assertJsonPath('data.publisher.name', 'Penguin Random House');
        $this->asToken($admin)->postJson('/api/v1/staff/suppliers', ['name' => 'Other Penguin', 'type' => 'publisher', 'publisher_id' => $publisher->id])
            ->assertUnprocessable()->assertJsonValidationErrors('publisher_id');
        $this->asToken($admin)->postJson('/api/v1/staff/suppliers', ['name' => 'Ingram', 'type' => 'distributor', 'publisher_id' => $publisher->id])
            ->assertUnprocessable()->assertJsonValidationErrors('publisher_id');
        $id = $this->asToken($admin)->postJson('/api/v1/staff/suppliers', ['name' => 'Ingram'])->assertCreated()->assertJsonPath('data.type', 'distributor')->json('data.id');

        $this->asToken($admin)->getJson('/api/v1/staff/suppliers?type=publisher')->assertJsonCount(1, 'data');
        $direct = Supplier::where('name', 'Penguin direct')->first();
        $this->asToken($admin)->patchJson("/api/v1/staff/suppliers/{$direct->id}", ['type' => 'distributor'])->assertOk()->assertJsonPath('data.publisher', null);
        $this->assertNotNull($id);
    }
}

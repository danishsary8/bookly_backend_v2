<?php

namespace Tests\Feature\Catalog;

use App\Models\Book;
use App\Models\BookVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class VariantCostPriceTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_admin_sets_and_reads_the_supplier_cost(): void
    {
        $admin = $this->staffToken(admin: true);
        $book = Book::factory()->create();

        $id = $this->asToken($admin)->postJson("/api/v1/staff/books/{$book->id}/variants", [
            'format' => 'paperback', 'sku' => 'COST-PB', 'price_usd' => '20.00', 'cost_price_usd' => '12.40', 'stock_quantity' => 3,
        ])->assertCreated()->assertJsonPath('data.cost_price_usd', '12.40')->json('data.id');

        $this->asToken($admin)->patchJson("/api/v1/staff/variants/{$id}", ['cost_price_usd' => '13'])
            ->assertOk()->assertJsonPath('data.cost_price_usd', '13.00');
        $this->asToken($admin)->patchJson("/api/v1/staff/variants/{$id}", ['cost_price_usd' => null])
            ->assertOk()->assertJsonPath('data.cost_price_usd', null);
        $this->asToken($admin)->patchJson("/api/v1/staff/variants/{$id}", ['cost_price_usd' => '1.234'])
            ->assertUnprocessable()->assertJsonValidationErrors('cost_price_usd');
    }

    public function test_staff_never_see_or_set_the_cost(): void
    {
        $staff = $this->staffToken();
        $variant = BookVariant::factory()->create(['cost_price_usd' => '9.00']);

        $this->asToken($staff)->patchJson("/api/v1/staff/variants/{$variant->id}", ['price_usd' => '15.00'])
            ->assertOk()->assertJsonMissingPath('data.cost_price_usd');
        $this->asToken($staff)->patchJson("/api/v1/staff/variants/{$variant->id}", ['cost_price_usd' => '1.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('cost_price_usd');
        $this->assertSame('9.00', $variant->refresh()->cost_price_usd);
    }

    public function test_a_restock_with_a_unit_cost_becomes_the_latest_cost(): void
    {
        $admin = $this->staffToken(admin: true);
        $variant = BookVariant::factory()->create(['cost_price_usd' => '9.00']);

        $this->asToken($admin)->postJson("/api/v1/staff/variants/{$variant->id}/restock", ['quantity' => 5])->assertSuccessful();
        $this->assertSame('9.00', $variant->refresh()->cost_price_usd);

        $this->asToken($admin)->postJson("/api/v1/staff/variants/{$variant->id}/restock", ['quantity' => 5, 'unit_cost_usd' => '7.25'])->assertSuccessful();
        $this->assertSame('7.25', $variant->refresh()->cost_price_usd);
    }
}

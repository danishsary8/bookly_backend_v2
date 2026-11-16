<?php

namespace Tests\Feature\Admin;

use App\Enums\BookFormat;
use App\Enums\InventoryReason;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\InventoryMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class InventoryReportTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
        $this->admin = $this->staffToken(admin: true);
    }

    private function sell(BookVariant $variant, int $qty, string $placedAt): void
    {
        $order = $this->deliveredOrder(Customer::factory()->create(), [[$variant, $qty]]);
        $order->update(['placed_at' => Carbon::parse($placedAt, 'UTC')]);
    }

    public function test_only_admins_and_valid_days(): void
    {
        $this->asToken($this->staffToken(admin: false))->getJson('/api/v1/staff/reports/inventory')->assertForbidden();
        $this->asToken($this->admin)->getJson('/api/v1/staff/reports/inventory?days=5')->assertUnprocessable();
    }

    public function test_values_runs_out_and_not_selling(): void
    {
        // Fast seller: 10 in stock, 30 sold in 30 days => 1/day => 10 days left, buy 30 - 10 = 20.
        $fast = BookVariant::factory()->create(['price_usd' => '20.00', 'stock_quantity' => 10, 'low_stock_threshold' => 10]);
        InventoryMovement::create(['book_variant_id' => $fast->id, 'change_qty' => 40, 'reason' => InventoryReason::Restock, 'unit_cost_usd' => '12.00']);
        $this->sell($fast, 30, '2026-10-01 03:00:00');

        // Not selling: last order 120 days ago, no cost recorded.
        $slow = BookVariant::factory()->create(['price_usd' => '15.00', 'stock_quantity' => 4, 'low_stock_threshold' => 1]);
        $this->sell($slow, 1, '2026-06-01 03:00:00');

        // Ebooks are not stock.
        BookVariant::factory()->create(['format' => BookFormat::Ebook, 'price_usd' => '5.00', 'stock_quantity' => 0]);

        $res = $this->asToken($this->admin)->getJson('/api/v1/staff/reports/inventory')->assertOk();

        $res->assertJsonPath('data.totals.copies', 14)
            ->assertJsonPath('data.totals.copies_without_cost', 4)
            ->assertJsonPath('data.totals.value_cost_usd', '120.00')
            ->assertJsonPath('data.totals.value_retail_usd', '260.00')
            ->assertJsonPath('data.totals.potential_profit_usd', '80.00')
            ->assertJsonPath('data.totals.runs_out_soon', 1)
            ->assertJsonPath('data.totals.dead_formats', 1)
            ->assertJsonPath('data.totals.dead_value_usd', '60.00')
            ->assertJsonPath('data.runs_out.0.book_variant_id', $fast->id)
            ->assertJsonPath('data.runs_out.0.days_left', 10)
            ->assertJsonPath('data.runs_out.0.suggested_buy', 20)
            ->assertJsonPath('data.not_selling.0.book_variant_id', $slow->id);

        $csv = $this->asToken($this->admin)->getJson('/api/v1/staff/reports/inventory?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Runs out soon', $csv);

        $this->asToken($this->admin)->getJson('/api/v1/staff/dashboard/purchases')->assertOk()
            ->assertJsonFragment(['variant_id' => $fast->id, 'sold_last_30_days' => 30, 'days_left' => 10]);
    }
}

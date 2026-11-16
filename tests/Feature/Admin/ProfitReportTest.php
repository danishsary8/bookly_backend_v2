<?php

namespace Tests\Feature\Admin;

use App\Enums\BookFormat;
use App\Enums\InventoryReason;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\OrderReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class ProfitReportTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.timezone' => 'Asia/Phnom_Penh']);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
        $this->admin = $this->staffToken(admin: true);
    }

    private function restock(BookVariant $variant, int $qty, ?string $cost, ?string $supplier = null, string $at = '2026-10-02 03:00:00'): void
    {
        $m = InventoryMovement::create([
            'book_variant_id' => $variant->id,
            'change_qty' => $qty,
            'reason' => InventoryReason::Restock,
            'unit_cost_usd' => $cost,
            'supplier' => $supplier,
        ]);
        $m->forceFill(['created_at' => Carbon::parse($at, 'UTC')])->save();
    }

    private function profit(string $query = 'from=2026-10-01&to=2026-10-10')
    {
        return $this->asToken($this->admin)->getJson("/api/v1/staff/reports/profit?{$query}");
    }

    public function test_only_admins_can_see_profit(): void
    {
        $staff = $this->staffToken(admin: false);
        $this->asToken($staff)->getJson('/api/v1/staff/reports/profit?from=2026-10-01&to=2026-10-10')->assertForbidden();
        $this->profit('from=2026-10-10&to=2026-10-01')->assertUnprocessable();
    }

    public function test_empty_range_returns_zeros(): void
    {
        $res = $this->profit('from=2026-01-01&to=2026-01-03')->assertOk();

        $res->assertJsonPath('data.totals.restock_spend_usd', '0.00')
            ->assertJsonPath('data.totals.gross_profit_usd', '0.00')
            ->assertJsonPath('data.totals.margin_percent', null)
            ->assertJsonPath('data.by_supplier', [])
            ->assertJsonPath('data.top_books', []);
        $this->assertCount(3, $res->json('data.series'));
    }

    public function test_profit_uses_weighted_average_cost_and_line_discounts(): void
    {
        $customer = Customer::factory()->create();
        $book = $this->physical('20.00');
        // 10 copies at $8 + 10 copies at $10 => average $9.00
        $this->restock($book, 10, '8.00', 'Monument Books');
        $this->restock($book, 10, '10.00', ' Monument Books ', '2026-10-03 03:00:00');
        // Restock without a cost: counted, not priced.
        $this->restock($book, 5, null);

        $order = $this->deliveredOrder($customer, [[$book, 3]], deliveredAt: Carbon::parse('2026-10-05 03:00:00', 'UTC'));
        // $5 of discount on the line => sales $55
        $order->items()->update(['discount_amount' => '5.00']);

        $res = $this->profit()->assertOk();

        $res->assertJsonPath('data.totals.restock_spend_usd', '180.00')
            ->assertJsonPath('data.totals.restock_units', 25)
            ->assertJsonPath('data.totals.restock_units_without_cost', 5)
            ->assertJsonPath('data.totals.sales_usd', '55.00')
            ->assertJsonPath('data.totals.cost_of_goods_usd', '27.00')
            ->assertJsonPath('data.totals.gross_profit_usd', '28.00')
            ->assertJsonPath('data.totals.margin_percent', 50.9)
            ->assertJsonPath('data.by_supplier.0.supplier', 'Monument Books')
            ->assertJsonPath('data.by_supplier.0.units', 20)
            ->assertJsonPath('data.by_supplier.0.spend_usd', '180.00')
            ->assertJsonPath('data.by_supplier.0.average_unit_cost_usd', '9.00')
            ->assertJsonPath('data.by_supplier.1.supplier', null)
            ->assertJsonPath('data.top_books.0.unit_cost_usd', '9.00')
            ->assertJsonPath('data.top_books.0.gross_profit_usd', '28.00');

        $day = collect($res->json('data.series'))->firstWhere('date', '2026-10-05');
        $this->assertSame('28.00', $day['gross_profit_usd']);
        $this->assertSame('100.00', collect($res->json('data.series'))->firstWhere('date', '2026-10-03')['restock_spend_usd']);
    }

    public function test_sales_without_a_known_cost_are_kept_out_of_the_margin(): void
    {
        $customer = Customer::factory()->create();
        $noCost = $this->physical('15.00');
        $ebook = BookVariant::factory()->create(['price_usd' => '6.00', 'format' => BookFormat::Ebook, 'stock_quantity' => 0]);

        $this->deliveredOrder($customer, [[$noCost, 2], [$ebook, 1]], deliveredAt: Carbon::parse('2026-10-06 03:00:00', 'UTC'));

        $this->profit()->assertOk()
            // Ebook: no stock cost, so it is all profit.
            ->assertJsonPath('data.totals.sales_usd', '6.00')
            ->assertJsonPath('data.totals.cost_of_goods_usd', '0.00')
            ->assertJsonPath('data.totals.sales_cost_unknown_usd', '30.00')
            ->assertJsonPath('data.totals.units_sold_cost_unknown', 2)
            ->assertJsonPath('data.cost_unknown.0.variant_id', $noCost->id)
            ->assertJsonPath('data.cost_unknown.0.sales_usd', '30.00');
    }

    public function test_refunds_reduce_profit_and_returned_copies_add_their_cost_back(): void
    {
        $customer = Customer::factory()->create();
        $book = $this->physical('20.00');
        $this->restock($book, 10, '12.00');
        $order = $this->deliveredOrder($customer, [[$book, 2]], deliveredAt: Carbon::parse('2026-10-04 03:00:00', 'UTC'));

        $return = OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'reason' => 'Damaged',
            'status' => 'refunded',
            'refund_amount' => '20.00',
            'requested_at' => now(),
            'resolved_at' => now(),
        ]);
        $return->items()->create(['order_item_id' => $order->items()->first()->id, 'quantity' => 1]);

        // Sales 40 - cost 24 = 16; minus refund 20, plus returned copy cost 12 => 8
        $this->profit()->assertOk()
            ->assertJsonPath('data.totals.gross_profit_usd', '16.00')
            ->assertJsonPath('data.totals.refunds_usd', '20.00')
            ->assertJsonPath('data.totals.returned_cost_usd', '12.00')
            ->assertJsonPath('data.totals.profit_after_refunds_usd', '8.00');
    }

    public function test_comparison_and_csv_export(): void
    {
        $customer = Customer::factory()->create();
        $book = $this->physical('10.00');
        $this->restock($book, 10, '5.00', null, '2026-09-20 03:00:00');
        $this->deliveredOrder($customer, [[$book, 1]], deliveredAt: Carbon::parse('2026-09-25 03:00:00', 'UTC'));
        $this->deliveredOrder($customer, [[$book, 2]], deliveredAt: Carbon::parse('2026-10-05 03:00:00', 'UTC'));

        $this->profit()->assertOk()
            ->assertJsonPath('data.totals.gross_profit_usd', '10.00')
            ->assertJsonPath('data.comparison.totals.gross_profit_usd', '5.00')
            ->assertJsonPath('data.comparison.change_percent.gross_profit', 100);

        $csv = $this->profit('from=2026-10-01&to=2026-10-10&format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Gross Profit (USD)', $csv);
        $this->assertStringContainsString('"Profit after refunds (USD)",10.00', $csv);
    }
}

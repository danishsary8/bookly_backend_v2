<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\ExchangeRate;
use App\Models\OrderReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    private string $admin;

    private string $staff;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.timezone' => 'Asia/Phnom_Penh']);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
        $this->admin = $this->staffToken(admin: true);
        $this->staff = $this->staffToken(admin: false);
    }

    public function test_non_admin_staff_cannot_access_reports(): void
    {
        $this->asToken($this->staff)->getJson('/api/v1/staff/reports/sales?from=2026-10-01&to=2026-10-10')->assertForbidden();
        $this->asToken($this->staff)->getJson('/api/v1/staff/reports/orders?from=2026-10-01&to=2026-10-10')->assertForbidden();
        $this->asToken($this->staff)->getJson('/api/v1/staff/reports/products?from=2026-10-01&to=2026-10-10')->assertForbidden();
        $this->asToken($this->staff)->getJson('/api/v1/staff/reports/customers?from=2026-10-01&to=2026-10-10')->assertForbidden();
        $this->asToken($this->staff)->getJson('/api/v1/staff/reports/funnel?from=2026-10-01&to=2026-10-10')->assertForbidden();
        $this->asToken($this->staff)->getJson('/api/v1/staff/reports/coupons?from=2026-10-01&to=2026-10-10')->assertForbidden();
    }

    public function test_customer_token_cannot_access_reports(): void
    {
        $customer = Customer::factory()->create();
        $token = $customer->createToken('customer', ['customer'])->plainTextToken;

        $this->asToken($token)->getJson('/api/v1/staff/reports/sales?from=2026-10-01&to=2026-10-10')->assertForbidden();
    }

    public function test_unauthenticated_request_is_unauthorized(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/staff/reports/sales?from=2026-10-01&to=2026-10-10')->assertUnauthorized();
    }

    public function test_date_range_validation(): void
    {
        // Missing parameters
        $this->asToken($this->admin)->getJson('/api/v1/staff/reports/sales')->assertUnprocessable();

        // End before start
        $this->asToken($this->admin)->getJson('/api/v1/staff/reports/sales?from=2026-10-10&to=2026-10-01')->assertUnprocessable();

        // Range > 366 days
        $this->asToken($this->admin)->getJson('/api/v1/staff/reports/sales?from=2024-01-01&to=2026-10-10')->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    }

    public function test_empty_range_returns_zeros(): void
    {
        $res = $this->asToken($this->admin)->getJson('/api/v1/staff/reports/sales?from=2026-01-01&to=2026-01-05')->assertOk();

        $res->assertJsonPath('data.totals.gross_revenue_usd', '0.00')
            ->assertJsonPath('data.totals.refunds_usd', '0.00')
            ->assertJsonPath('data.totals.net_revenue_usd', '0.00')
            ->assertJsonPath('data.totals.orders_count', 0)
            ->assertJsonPath('data.totals.items_sold', 0);

        $this->assertCount(5, $res->json('data.series'));
        $this->assertSame('0.00', $res->json('data.series.0.gross_revenue_usd'));
    }

    public function test_sales_report_computes_exact_cash_basis_totals_and_comparison(): void
    {
        ExchangeRate::create(['base_currency' => 'USD', 'target_currency' => 'KHR', 'rate' => '4100.000000', 'effective_at' => now()->subYear()]);
        $customer = Customer::factory()->create();

        // Current period: 2026-10-01 to 2026-10-10 (10 days)
        // Delivered on 2026-10-09: 2 x $20 = $40 total
        $a = $this->deliveredOrder($customer, [[$this->physical('20.00'), 2]], deliveredAt: Carbon::parse('2026-10-09 18:00:00', 'UTC'));
        $a->update(['placed_at' => Carbon::parse('2026-10-08 03:00:00', 'UTC')]);

        // Delivered on 2026-10-05: 1 x $20 = $20 total
        $b = $this->deliveredOrder($customer, [[$this->physical('20.00'), 1]], deliveredAt: Carbon::parse('2026-10-05 03:00:00', 'UTC'));
        $b->update(['placed_at' => Carbon::parse('2026-10-04 03:00:00', 'UTC')]);

        // Refund of $15 on order a
        OrderReturn::create([
            'order_id' => $a->id,
            'customer_id' => $customer->id,
            'reason' => 'Defective',
            'status' => 'refunded',
            'refund_amount' => '15.00',
            'requested_at' => now(),
            'resolved_at' => now(),
        ]);

        // Previous period of equal length (2026-09-21 to 2026-09-30):
        // Delivered on 2026-09-25: 1 x $30 = $30
        $prev = $this->deliveredOrder($customer, [[$this->physical('30.00'), 1]], deliveredAt: Carbon::parse('2026-09-25 03:00:00', 'UTC'));
        $prev->update(['placed_at' => Carbon::parse('2026-09-24 03:00:00', 'UTC')]);

        $res = $this->asToken($this->admin)->getJson('/api/v1/staff/reports/sales?from=2026-10-01&to=2026-10-10')->assertOk();

        // Gross: 40 + 20 = 60.00
        // Refunds: 15.00
        // Net: 45.00
        $res->assertJsonPath('data.totals.gross_revenue_usd', '60.00')
            ->assertJsonPath('data.totals.refunds_usd', '15.00')
            ->assertJsonPath('data.totals.net_revenue_usd', '45.00')
            ->assertJsonPath('data.totals.orders_count', 2)
            ->assertJsonPath('data.totals.items_sold', 3)
            ->assertJsonPath('data.totals.gross_revenue_khr', '246000')
            ->assertJsonPath('data.totals.net_revenue_khr', '184500');

        // Previous period gross: 30.00, net: 30.00
        $res->assertJsonPath('data.comparison.totals.gross_revenue_usd', '30.00')
            ->assertJsonPath('data.comparison.totals.net_revenue_usd', '30.00');
        $this->assertEquals(50.0, $res->json('data.comparison.change_percent.net_revenue'));
    }

    public function test_monthly_series_does_not_skip_short_months(): void
    {
        $customer = Customer::factory()->create();
        $feb = $this->deliveredOrder($customer, [[$this->physical('20.00'), 1]], deliveredAt: Carbon::parse('2026-02-14 05:00:00', 'UTC'));
        $feb->update(['placed_at' => Carbon::parse('2026-02-13 05:00:00', 'UTC')]);

        $res = $this->asToken($this->admin)->getJson('/api/v1/staff/reports/sales?from=2026-01-31&to=2026-03-31&group=month')->assertOk();

        $this->assertSame(['2026-01-01', '2026-02-01', '2026-03-01'], array_column($res->json('data.series'), 'date'));
        $this->assertSame('20.00', $res->json('data.series.1.gross_revenue_usd'));
    }

    public function test_orders_report_counts_delivery_areas_with_the_checkout_rule(): void
    {
        $customer = Customer::factory()->create();
        foreach (['Phnom Penh', 'phnom-penh', 'ភ្នំពេញ', 'Siem Reap'] as $i => $city) {
            $order = $this->deliveredOrder($customer, [[$this->physical('10.00'), 1]]);
            $order->update(['shipping_city' => $city, 'shipping_state' => null, 'placed_at' => Carbon::parse('2026-10-0'.($i + 2).' 04:00:00', 'UTC')]);
        }

        $this->asToken($this->admin)->getJson('/api/v1/staff/reports/orders?from=2026-10-01&to=2026-10-10')->assertOk()
            ->assertJsonPath('data.orders_by_delivery_area.phnom_penh', 3)
            ->assertJsonPath('data.orders_by_delivery_area.provinces', 1);
    }

    public function test_orders_report(): void
    {
        $customer = Customer::factory()->create();

        $delivered = $this->deliveredOrder($customer, [[$this->physical('20.00'), 1]], deliveredAt: Carbon::parse('2026-10-05 08:00:00', 'UTC'));
        $delivered->update(['placed_at' => Carbon::parse('2026-10-05 04:00:00', 'UTC')]); // 4 hours fulfillment

        $cancelled = $this->deliveredOrder($customer, [[$this->physical('10.00'), 1]]);
        $cancelled->update(['status' => OrderStatus::Cancelled, 'placed_at' => Carbon::parse('2026-10-06 00:00:00', 'UTC')]);

        $res = $this->asToken($this->admin)->getJson('/api/v1/staff/reports/orders?from=2026-10-01&to=2026-10-10')->assertOk();

        $res->assertJsonPath('data.total_orders', 2)
            ->assertJsonPath('data.orders_by_status.delivered', 1)
            ->assertJsonPath('data.orders_by_status.cancelled', 1);
        $this->assertEquals(50.0, $res->json('data.cancellation_rate'));
        $this->assertEquals(4.0, $res->json('data.average_delivery_hours'));
    }

    public function test_products_report(): void
    {
        $customer = Customer::factory()->create();
        $variantA = $this->physical('25.00');
        $variantB = $this->physical('10.00');

        $order = $this->deliveredOrder($customer, [[$variantA, 2], [$variantB, 1]]);
        $order->update(['placed_at' => Carbon::parse('2026-10-05 00:00:00', 'UTC')]);

        $res = $this->asToken($this->admin)->getJson('/api/v1/staff/reports/products?from=2026-10-01&to=2026-10-10&limit=5')->assertOk();

        $this->assertNotEmpty($res->json('data.best_sellers_by_units'));
        $this->assertSame(2, $res->json('data.best_sellers_by_units.0.units_sold'));
        $this->assertSame('50.00', $res->json('data.best_sellers_by_revenue.0.revenue_usd'));
    }

    public function test_customers_report(): void
    {
        $c1 = Customer::factory()->create([
            'email_verified_at' => now(),
            'created_at' => Carbon::parse('2026-10-02 00:00:00', 'UTC'),
        ]);
        $c2 = Customer::factory()->create([
            'email_verified_at' => now(),
            'created_at' => Carbon::parse('2026-10-03 00:00:00', 'UTC'),
        ]);

        $o1 = $this->deliveredOrder($c1, [[$this->physical('40.00'), 1]]);
        $o1->update(['placed_at' => Carbon::parse('2026-10-04 00:00:00', 'UTC')]);

        $o2 = $this->deliveredOrder($c1, [[$this->physical('10.00'), 1]]);
        $o2->update(['placed_at' => Carbon::parse('2026-10-05 00:00:00', 'UTC')]);

        $o3 = $this->deliveredOrder($c2, [[$this->physical('15.00'), 1]]);
        $o3->update(['placed_at' => Carbon::parse('2026-10-05 00:00:00', 'UTC')]);

        $res = $this->asToken($this->admin)->getJson('/api/v1/staff/reports/customers?from=2026-10-01&to=2026-10-10')->assertOk();

        // 2 customers had orders; c1 had 2 orders, c2 had 1 -> returning rate = 1 / 2 = 50.0%
        $res->assertJsonPath('data.total_new_customers', 2)
            ->assertJsonPath('data.top_customers.0.customer_id', $c1->id)
            ->assertJsonPath('data.top_customers.0.total_spent_usd', '50.00');
        $this->assertEquals(50.0, $res->json('data.returning_customer_rate'));
    }

    public function test_funnel_report(): void
    {
        $customer = Customer::factory()->create();
        $order = $this->deliveredOrder($customer, [[$this->physical('20.00'), 1]]);
        $order->update(['placed_at' => Carbon::parse('2026-10-05 00:00:00', 'UTC')]);

        OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'reason' => 'Defective',
            'status' => 'refunded',
            'refund_amount' => '20.00',
            'requested_at' => now(),
            'resolved_at' => now(),
        ]);

        $res = $this->asToken($this->admin)->getJson('/api/v1/staff/reports/funnel?from=2026-10-01&to=2026-10-10')->assertOk();

        $res->assertJsonPath('data.steps.0.key', 'carts_with_items')
            ->assertJsonPath('data.steps.1.key', 'orders_placed')
            ->assertJsonPath('data.steps.2.key', 'orders_delivered')
            ->assertJsonPath('data.steps.3.key', 'orders_returned')
            ->assertJsonPath('data.steps.1.count', 1)
            ->assertJsonPath('data.steps.2.count', 1)
            ->assertJsonPath('data.steps.3.count', 1);
    }

    public function test_coupons_report(): void
    {
        $res = $this->asToken($this->admin)->getJson('/api/v1/staff/reports/coupons?from=2026-10-01&to=2026-10-10')->assertOk();
        $res->assertJsonStructure(['data' => ['period', 'coupons', 'refunds' => ['count', 'amount_usd', 'by_reason']]]);
    }

    public function test_csv_export_has_header_and_utf8_bom(): void
    {
        $res = $this->asToken($this->admin)->get('/api/v1/staff/reports/sales?from=2026-10-01&to=2026-10-10&format=csv');
        $res->assertOk();
        $res->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $res->streamedContent();
        // BOM \xEF\xBB\xBF is 3 bytes
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('Period / Date', $content);
        $this->assertStringContainsString('Net Revenue (USD)', $content);
    }
}

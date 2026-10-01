<?php

namespace Tests\Feature\Admin;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\OrderReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.timezone' => 'Asia/Phnom_Penh']);
        // 10:00 UTC = 17:00 in Phnom Penh on 2026-10-10.
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
        $this->admin = $this->staffToken(admin: true);
        $customer = Customer::factory()->create();

        // Delivered 18:00 UTC on the 9th = 01:00 on the 10th in Phnom Penh, so it is "today" for the shop.
        $a = $this->deliveredOrder($customer, [[$this->physical('20.00'), 2]], deliveredAt: Carbon::parse('2026-10-09 18:00:00', 'UTC'));
        $b = $this->deliveredOrder($customer, [[$this->physical('20.00'), 1]], deliveredAt: Carbon::parse('2026-10-05 03:00:00', 'UTC'));
        $old = $this->deliveredOrder($customer, [[$this->physical('99.00'), 1]], deliveredAt: Carbon::parse('2026-08-01 03:00:00', 'UTC'));
        $old->update(['placed_at' => Carbon::parse('2026-07-30 03:00:00', 'UTC')]);
        $a->update(['placed_at' => Carbon::parse('2026-10-08 03:00:00', 'UTC')]);
        $b->update(['placed_at' => Carbon::parse('2026-10-04 03:00:00', 'UTC')]);

        $pending = $this->deliveredOrder($customer, [[$this->physical('7.00'), 1]]);
        $pending->update(['status' => OrderStatus::Pending]);
        $pending->statusHistory()->delete();

        OrderReturn::create(['order_id' => $a->id, 'customer_id' => $customer->id, 'reason' => 'x', 'status' => 'refunded',
            'refund_amount' => '15.00', 'requested_at' => now(), 'resolved_at' => now()]);
        OrderReturn::create(['order_id' => $b->id, 'customer_id' => $customer->id, 'reason' => 'y', 'status' => 'requested', 'requested_at' => now()]);
    }

    public function test_today_uses_the_shop_timezone_and_cash_basis_revenue(): void
    {
        $this->asToken($this->admin)->getJson('/api/v1/staff/dashboard/summary?period=today')->assertOk()
            ->assertJsonPath('data.period.from', '2026-10-10')
            ->assertJsonPath('data.period.timezone', 'Asia/Phnom_Penh')
            ->assertJsonPath('data.revenue.gross_usd', '40.00')
            ->assertJsonPath('data.revenue.refunds_usd', '15.00')
            ->assertJsonPath('data.revenue.net_usd', '25.00')
            ->assertJsonPath('data.delivered_orders', 1)
            ->assertJsonPath('data.average_order_value_usd', '40.00')
            ->assertJsonPath('data.orders_placed', 1) // only the pending one was placed today
            ->assertJsonPath('data.orders_by_status.pending', 1)
            ->assertJsonPath('data.open_returns', 1);
    }

    public function test_seven_days_best_sellers_and_low_stock(): void
    {
        BookVariant::factory()->create(['stock_quantity' => 2, 'low_stock_threshold' => 5]);
        BookVariant::factory()->format(BookFormat::Ebook)->create(['stock_quantity' => 0]);

        $data = $this->asToken($this->admin)->getJson('/api/v1/staff/dashboard/summary?period=7d')->assertOk()
            ->assertJsonPath('data.revenue.gross_usd', '60.00')
            ->assertJsonPath('data.delivered_orders', 2)
            ->assertJsonPath('data.average_order_value_usd', '30.00')
            ->assertJsonPath('data.orders_placed', 3)
            ->json('data');

        $this->assertSame(2, $data['best_sellers'][0]['copies_sold']);
        $this->assertSame('40.00', $data['best_sellers'][0]['sales_usd']);
        $this->assertCount(1, $data['low_stock'], 'only active physical formats at or below the threshold');
        $this->assertSame(2, $data['low_stock'][0]['stock_quantity']);
    }

    public function test_daily_sales_rows_are_zero_filled_in_shop_days(): void
    {
        $rows = collect($this->asToken($this->admin)->getJson('/api/v1/staff/dashboard/sales?period=7d')->assertOk()
            ->assertJsonPath('meta.from', '2026-10-04')->assertJsonPath('meta.to', '2026-10-10')
            ->json('data'))->keyBy('date');

        $this->assertCount(7, $rows);
        $this->assertSame('40.00', $rows['2026-10-10']['gross_revenue_usd']);
        $this->assertSame('25.00', $rows['2026-10-10']['net_revenue_usd']);
        $this->assertSame('0.00', $rows['2026-10-09']['gross_revenue_usd'], 'the 18:00 UTC delivery belongs to the 10th in Phnom Penh');
        $this->assertSame('20.00', $rows['2026-10-05']['gross_revenue_usd']);
        $this->assertSame(1, $rows['2026-10-04']['orders_placed']);
        $this->assertSame('0.00', $rows['2026-10-06']['gross_revenue_usd']);
    }

    public function test_custom_period_validation_and_admin_only(): void
    {
        $this->asToken($this->admin)->getJson('/api/v1/staff/dashboard/summary?period=custom&from=2026-08-01&to=2026-08-31')->assertOk()
            ->assertJsonPath('data.revenue.gross_usd', '99.00');
        $this->asToken($this->admin)->getJson('/api/v1/staff/dashboard/summary?period=custom')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->asToken($this->admin)->getJson('/api/v1/staff/dashboard/summary?period=custom&from=2024-01-01&to=2026-01-01')->assertUnprocessable();
        $this->asToken($this->admin)->getJson('/api/v1/staff/dashboard/summary?period=year')->assertUnprocessable();

        $this->asToken($this->staffToken())->getJson('/api/v1/staff/dashboard/summary')->assertForbidden();
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\Author;
use App\Models\Customer;
use App\Services\Orders\DeliveryArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class ShopSettingsAndGoalTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.timezone' => 'Asia/Phnom_Penh']);
        $this->travelTo(Carbon::parse('2026-11-10 05:00:00', 'UTC')); // 10 Nov, 12:00 in Phnom Penh
    }

    public function test_admin_changes_delivery_fees_and_checkout_uses_them(): void
    {
        $admin = $this->staffToken(admin: true);
        $this->asToken($admin)->getJson('/api/v1/staff/settings/shop')->assertOk()
            ->assertJsonPath('data.shipping_fee_phnom_penh_usd', config('shop.shipping_fees.phnom_penh'));

        $this->asToken($this->staffToken(admin: false))->patchJson('/api/v1/staff/settings/shop', ['shipping_fee_phnom_penh_usd' => '2.00'])->assertForbidden();

        $this->asToken($admin)->patchJson('/api/v1/staff/settings/shop', [
            'shipping_fee_phnom_penh_usd' => '2.25',
            'shipping_fee_provinces_usd' => '4',
            'free_shipping_threshold_usd' => '0',
            'monthly_sales_goal_usd' => '1000',
        ])->assertOk()
            ->assertJsonPath('data.shipping_fee_phnom_penh_usd', '2.25')
            ->assertJsonPath('data.shipping_fee_provinces_usd', '4.00')
            ->assertJsonPath('data.free_shipping_threshold_usd', null)
            ->assertJsonPath('data.monthly_sales_goal_usd', '1000.00');

        $this->assertSame('2.25', DeliveryArea::fee(DeliveryArea::PHNOM_PENH));
        $this->assertSame('4.00', DeliveryArea::fee(DeliveryArea::PROVINCES));
        $this->assertNull(DeliveryArea::freeShippingThreshold());
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'shop_settings.updated']);

        $this->asToken($admin)->patchJson('/api/v1/staff/settings/shop', ['shipping_fee_provinces_usd' => '-1'])->assertUnprocessable();
    }

    public function test_goal_progress_for_this_month(): void
    {
        $admin = $this->staffToken(admin: true);
        $this->asToken($admin)->getJson('/api/v1/staff/dashboard/goal')->assertOk()->assertJsonPath('data.goal_usd', null)->assertJsonPath('data.on_track', null);

        $this->asToken($admin)->patchJson('/api/v1/staff/settings/shop', ['monthly_sales_goal_usd' => '300'])->assertOk();
        $this->deliveredOrder(Customer::factory()->create(), [[$this->physical('50.00'), 2]], deliveredAt: Carbon::parse('2026-11-05 03:00:00', 'UTC'));

        Cache::flush(); // reports are cached for 5 minutes, like the dashboard

        // 30 days, 10 passed: expected 100; 100 earned => on track; 200 left over 20 days => 10/day.
        $this->asToken($admin)->getJson('/api/v1/staff/dashboard/goal')->assertOk()
            ->assertJsonPath('data.month', '2026-11')
            ->assertJsonPath('data.days_passed', 10)
            ->assertJsonPath('data.net_revenue_usd', '100.00')
            ->assertJsonPath('data.percent', 33.3)
            ->assertJsonPath('data.expected_by_today_usd', '100.00')
            ->assertJsonPath('data.on_track', true)
            ->assertJsonPath('data.needed_per_day_usd', '10.00');
    }

    public function test_top_authors_customer_numbers_and_book_sales(): void
    {
        $admin = $this->staffToken(admin: true);
        $variant = $this->physical('10.00');
        $author = Author::factory()->create(['name' => 'Jules Verne']);
        $variant->book->authors()->attach($author);
        $other = $this->physical('5.00');

        $old = Customer::factory()->create();
        $this->deliveredOrder($old, [[$other, 1]])->update(['placed_at' => Carbon::parse('2026-09-01', 'UTC')]);
        $this->deliveredOrder($old, [[$variant, 3]])->update(['placed_at' => Carbon::parse('2026-11-08', 'UTC')]);
        $this->deliveredOrder(Customer::factory()->create(), [[$variant, 1], [$other, 2]])->update(['placed_at' => Carbon::parse('2026-11-09', 'UTC')]);

        $this->asToken($admin)->getJson('/api/v1/staff/reports/products?from=2026-11-01&to=2026-11-10')->assertOk()
            ->assertJsonPath('data.top_authors.0.name', 'Jules Verne')
            ->assertJsonPath('data.top_authors.0.units_sold', 4);

        $this->asToken($admin)->getJson('/api/v1/staff/reports/customers?from=2026-11-01&to=2026-11-10')->assertOk()
            ->assertJsonPath('data.customers_ordered', 2)
            ->assertJsonPath('data.first_time_customers', 1)
            ->assertJsonPath('data.returning_customers', 1)
            ->assertJsonPath('data.average_spend_usd', '25.00');

        $this->asToken($this->staffToken(admin: false))->getJson("/api/v1/staff/books/{$variant->book_id}/sales")->assertOk()
            ->assertJsonPath('data.sold_30_days', 4)
            ->assertJsonPath('data.sales_90_days_usd', '40.00')
            ->assertJsonPath('data.rank_90_days', 1)
            ->assertJsonPath('data.books_ranked', 2)
            ->assertJsonPath('data.by_format.0.units_sold', 4);
    }
}

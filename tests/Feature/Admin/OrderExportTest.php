<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class OrderExportTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    public function test_exports_orders_in_range_as_csv_with_formula_cells_escaped(): void
    {
        config(['shop.timezone' => 'Asia/Phnom_Penh']);
        $inRange = $this->deliveredOrder(Customer::factory()->create(['name' => '=HYPERLINK("http://evil")', 'email' => 'a@example.com']), [[$this->physical('12.50'), 2]]);
        $inRange->update(['placed_at' => Carbon::parse('2026-10-09 18:30:00', 'UTC')]); // 01:30 on the 10th in Phnom Penh
        $outside = $this->deliveredOrder(Customer::factory()->create(), [[$this->physical(), 1]]);
        $outside->update(['placed_at' => Carbon::parse('2026-10-08 10:00:00', 'UTC')]);

        $response = $this->asToken($this->staffToken(admin: true))->get('/api/v1/staff/orders/export?from=2026-10-10&to=2026-10-10');

        $response->assertOk()->assertDownload('orders-2026-10-10-to-2026-10-10.csv');
        $rows = array_map('str_getcsv', array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF"))));

        $this->assertCount(2, $rows); // header + 1 order
        $row = array_combine($rows[0], $rows[1]);
        $this->assertSame($inRange->order_number, $row['order_number']);
        $this->assertSame('2026-10-10 01:30', $row['placed_at']);
        $this->assertSame("'=HYPERLINK(\"http://evil\")", $row['customer_name']);
        $this->assertSame('2', $row['items']);
        $this->assertSame('25.00', $row['total_usd']);
        $this->assertSame('succeeded', $row['payment_status']);
    }

    public function test_validation_and_admin_only(): void
    {
        $admin = $this->staffToken(admin: true);

        $this->asToken($admin)->getJson('/api/v1/staff/orders/export')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->asToken($admin)->getJson('/api/v1/staff/orders/export?from=2024-01-01&to=2026-01-01')->assertUnprocessable();
        $this->asToken($this->staffToken())->getJson('/api/v1/staff/orders/export?from=2026-10-01&to=2026-10-02')->assertForbidden();
    }
}

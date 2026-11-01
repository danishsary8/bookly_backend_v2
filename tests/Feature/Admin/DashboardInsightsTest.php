<?php

namespace Tests\Feature\Admin;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Offer;
use App\Models\Order;
use App\Models\OrderReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/** GET /staff/dashboard/insights and /staff/dashboard/counts (docs/superpowers/specs/2026-11-01-bi-dashboard-design.md). */
class DashboardInsightsTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->staffToken(admin: true);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'UTC')); // 17:00 in Phnom Penh
    }

    private function insights(string $query = 'period=7d'): array
    {
        return $this->asToken($this->admin)->getJson("/api/v1/staff/dashboard/insights?{$query}")->assertOk()->json('data');
    }

    private function returnFor(Customer $customer, string $status, ?string $refund = null, ?Carbon $resolvedAt = null): OrderReturn
    {
        $order = $this->deliveredOrder($customer, [[$this->physical(), 1]], deliveredAt: Carbon::parse('2026-08-01 03:00:00', 'UTC'));
        $order->update(['placed_at' => Carbon::parse('2026-08-01 03:00:00', 'UTC')]);

        return OrderReturn::create([
            'order_id' => $order->id, 'customer_id' => $customer->id, 'reason' => 'Damaged', 'status' => $status,
            'refund_amount' => $refund, 'requested_at' => now()->subDay(), 'resolved_at' => $resolvedAt,
        ]);
    }

    private function placedAt(Order $order, string $utc): Order
    {
        $order->update(['placed_at' => Carbon::parse($utc, 'UTC')]);

        return $order;
    }

    public function test_trend_has_one_row_per_day_with_the_previous_period_alongside(): void
    {
        $customer = Customer::factory()->create();
        // Delivered on 8 Oct (this period) and on 1 Oct (the previous 7 days): $20 and $10.
        $this->deliveredOrder($customer, [[$this->physical('10.00'), 2]], deliveredAt: Carbon::parse('2026-10-08 03:00:00', 'UTC'));
        $this->deliveredOrder($customer, [[$this->physical('10.00'), 1]], deliveredAt: Carbon::parse('2026-10-01 03:00:00', 'UTC'));
        // A refund of $5 on 8 Oct.
        $this->returnFor($customer, 'refunded', '5.00', Carbon::parse('2026-10-08 05:00:00', 'UTC'));

        $trend = $this->insights()['trend'];
        $this->assertCount(7, $trend);
        $this->assertSame(['2026-10-04', '2026-09-27'], [$trend[0]['date'], $trend[0]['previous_date']]);
        $oct8 = collect($trend)->firstWhere('date', '2026-10-08');
        $this->assertSame('15.00', $oct8['net_revenue_usd']);
        $this->assertSame('20.00', $oct8['average_order_usd']);
        $this->assertSame('2026-10-01', $oct8['previous_date']);
        $this->assertSame('10.00', $oct8['previous_net_revenue_usd']);
        $this->assertSame('10.00', $oct8['previous_average_order_usd']);
    }

    public function test_dead_stock_and_stock_value_use_printed_books_at_selling_price(): void
    {
        $customer = Customer::factory()->create();
        $sellsWell = $this->physical('10.00', stock: 5);
        $sleeping = $this->physical('20.00', stock: 3);   // last sold 90 days ago -> dead stock
        $neverSold = $this->physical('8.00', stock: 2);   // never sold -> dead stock
        $this->physical('99.00', stock: 0);                // nothing in stock: never dead stock
        BookVariant::factory()->format(BookFormat::Ebook)->create(['price_usd' => '5.00', 'stock_quantity' => 100]); // digital: ignored
        $this->placedAt($this->deliveredOrder($customer, [[$sellsWell, 1]]), '2026-10-01 03:00:00');
        $this->placedAt($this->deliveredOrder($customer, [[$sleeping, 1]]), '2026-07-01 03:00:00');
        $cancelled = $this->placedAt($this->deliveredOrder($customer, [[$neverSold, 1]]), '2026-10-05 03:00:00');
        $cancelled->update(['status' => OrderStatus::Cancelled]); // a cancelled sale does not count

        $data = $this->insights();
        $dead = $data['dead_stock'];
        $this->assertSame([60, 2, 5, '76.00'], [$dead['days'], $dead['variants'], $dead['copies'], $dead['value_usd']]);
        $this->assertSame([$sleeping->id, $neverSold->id], array_column($dead['items'], 'book_variant_id')); // biggest value first
        $this->assertSame('2026-07-01T03:00:00Z', $dead['items'][0]['last_sold_at']);
        $this->assertNull($dead['items'][1]['last_sold_at']);
        $this->assertSame(['copies' => 10, 'value_usd' => '126.00', 'value_khr' => null, 'basis' => 'selling_price'], $data['stock_value']);
    }

    public function test_top_authors_provinces_promotions_and_repeat_customers(): void
    {
        $dara = Customer::factory()->create();
        $sokha = Customer::factory()->create();
        $austen = Author::factory()->create(['name' => 'Jane Austen']);
        $doyle = Author::factory()->create(['name' => 'Arthur Conan Doyle']);
        $emma = $this->physical('10.00');
        $emma->book->authors()->attach($austen);
        $holmes = $this->physical('8.00');
        $holmes->book->authors()->attach($doyle);

        $this->placedAt($this->deliveredOrder($dara, [[$emma, 1]]), '2026-09-01 03:00:00'); // before the period: Dara returns
        $a = $this->placedAt($this->deliveredOrder($dara, [[$emma, 2]]), '2026-10-08 03:00:00');
        $b = $this->placedAt($this->deliveredOrder($sokha, [[$holmes, 1]]), '2026-10-09 03:00:00');
        $c = $this->placedAt($this->deliveredOrder($sokha, [[$holmes, 1]]), '2026-10-09 04:00:00');
        $a->update(['shipping_city' => 'Siem Reap', 'coupon_id' => Coupon::factory()->create()->id, 'discount_amount' => '2.00']);
        $b->update(['shipping_city' => 'Unknown town', 'shipping_state' => null]);
        $offer = Offer::factory()->create();
        $c->update(['offer_id' => $offer->id, 'offer_name' => $offer->name, 'discount_amount' => '1.20']);

        $data = $this->insights();
        $this->assertSame(['Jane Austen', 2, '20.00'], [$data['top_authors'][0]['name'], $data['top_authors'][0]['copies_sold'], $data['top_authors'][0]['sales_usd']]);
        $this->assertSame(['Arthur Conan Doyle', 2], [$data['top_authors'][1]['name'], $data['top_authors'][1]['copies_sold']]);

        $provinces = collect($data['provinces'])->keyBy('name');
        $this->assertSame(1, $provinces['Phnom Penh']['orders']);
        $this->assertSame('siem_reap', $provinces['Siem Reap']['code']);
        $this->assertSame(1, $provinces['Unknown']['orders']);
        $this->assertNull($provinces['Unknown']['code']);

        $this->assertSame([
            'orders' => 3, 'offer_orders' => 1, 'offer_discount_usd' => '1.20', 'coupon_orders' => 1, 'coupon_discount_usd' => '2.00', 'plain_orders' => 1,
        ], $data['promotions']);
        $this->assertEquals(['customers' => 2, 'returning' => 1, 'percent' => 50.0], $data['repeat_customer_rate']);
    }

    public function test_menu_counts_for_all_staff_and_insights_for_admins_only(): void
    {
        $customer = Customer::factory()->create();
        $this->deliveredOrder($customer, [[$this->physical(), 1]])->update(['status' => OrderStatus::Pending]);
        Order::factory()->for($customer)->create(['status' => OrderStatus::Pending]);
        $this->returnFor($customer, 'requested');
        BookVariant::factory()->create(['stock_quantity' => 1, 'low_stock_threshold' => 5]);

        $staff = $this->staffToken();
        $this->asToken($staff)->getJson('/api/v1/staff/dashboard/counts')->assertOk()
            ->assertExactJson(['data' => ['pending_orders' => 2, 'open_returns' => 1, 'low_stock' => 1]]);
        $this->asToken($staff)->getJson('/api/v1/staff/dashboard/insights')->assertForbidden();
        $customerToken = $customer->createToken('t', ['customer'])->plainTextToken;
        $this->asToken($customerToken)->getJson('/api/v1/staff/dashboard/counts')->assertForbidden();
    }

    public function test_query_count_does_not_grow_with_orders_or_books(): void
    {
        $count = function (): int {
            Cache::flush();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->asToken($this->admin)->getJson('/api/v1/staff/dashboard/insights?period=30d')->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $customer = Customer::factory()->create();
        $first = $this->physical('12.00');
        $first->book->authors()->attach(Author::factory()->create());
        $this->deliveredOrder($customer, [[$first, 1]]);
        $count(); // first request: token "last used" stamp, exchange rate and sign-in lookups run once
        $few = $count();
        foreach (range(1, 8) as $i) {
            $variant = $this->physical('12.00');
            $variant->book->authors()->attach(Author::factory()->create());
            $this->deliveredOrder($customer, [[$variant, 1]])->update(['shipping_city' => ['Siem Reap', 'Kampot', 'Battambang'][$i % 3]]);
        }
        $this->assertSame($few, $count());
    }

    public function test_dashboard_ignores_book_in_trash_and_validates_the_period(): void
    {
        $trashed = $this->physical('10.00', stock: 4);
        Book::whereKey($trashed->book_id)->first()->delete();
        $this->assertSame(0, $this->insights()['dead_stock']['variants']);
        $this->asToken($this->admin)->getJson('/api/v1/staff/dashboard/insights?period=custom&from=2026-10-10&to=2026-10-01')
            ->assertUnprocessable();
    }

    /** Production caches on files, which refuse to read objects back: both answers must be plain arrays. */
    public function test_insights_and_counts_survive_the_file_cache(): void
    {
        config(['cache.default' => 'file']);
        Cache::flush();
        $customer = Customer::factory()->create();
        $variant = $this->physical('10.00');
        $variant->book->authors()->attach(Author::factory()->create());
        $this->deliveredOrder($customer, [[$variant, 1]]);

        foreach (['/api/v1/staff/dashboard/insights?period=30d', '/api/v1/staff/dashboard/counts'] as $url) {
            $first = $this->asToken($this->admin)->getJson($url)->assertOk()->json();
            $second = $this->asToken($this->admin)->getJson($url)->assertOk();
            $this->assertStringNotContainsString('__PHP_Incomplete_Class', $second->getContent(), $url);
            $this->assertSame($first, $second->json(), $url);
        }
        Cache::flush();
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

/** The staff Books list shows and sorts by copies sold in the last 90 days (owner 2026-11-12). */
class StaffBooksSoldTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    public function test_the_list_shows_recent_sales_and_sorts_by_them(): void
    {
        $customer = Customer::factory()->create();
        $variant = fn (string $title) => BookVariant::factory()->create(['book_id' => Book::factory()->create(['title' => $title])->id, 'price_usd' => '10.00']);
        [$hit, $steady, $old, $cancelled, $none] = [$variant('Hit'), $variant('Steady'), $variant('Old'), $variant('Cancelled'), $variant('None')];

        $this->deliveredOrder($customer, [[$hit, 3], [$steady, 1]]);
        $this->deliveredOrder($customer, [[$hit, 2]]);
        $this->deliveredOrder($customer, [[$old, 9]])->update(['placed_at' => now()->subDays(120)]); // outside the 90 days
        $this->deliveredOrder($customer, [[$cancelled, 4]])->update(['status' => 'cancelled']);

        $token = $this->staffToken();
        $sold = fn (string $query) => collect($this->asToken($token)->getJson('/api/v1/staff/books?'.$query)->assertOk()->json('data'))->pluck('sold_recent', 'title')->all();

        $this->assertSame(['Hit' => 5, 'Steady' => 1], array_slice($sold('sort=most_sold'), 0, 2, true));
        $this->assertSame(0, $sold('sort=most_sold')['Old']);
        $this->assertSame(0, $sold('sort=most_sold')['Cancelled']);
        $this->assertSame(['Steady', 'Hit'], array_slice(array_keys($sold('sort=least_sold')), -2));
        $this->assertArrayHasKey('None', $sold('')); // default order still lists every book, with the number
        $this->asToken($token)->getJson('/api/v1/staff/books?sort=price')->assertJsonValidationErrors('sort');
    }
}

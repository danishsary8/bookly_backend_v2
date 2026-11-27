<?php

namespace Tests\Feature\Platform;

use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The docs page's one-click sign-in: a short-lived customer token for the shared demo account only, no password. */
class DemoTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_gives_a_two_hour_customer_token_for_the_demo_account(): void
    {
        Customer::factory()->create(['email' => DemoSeeder::EMAIL]);

        $res = $this->postJson('/api/v1/demo/token')->assertOk()
            ->assertJsonPath('customer.email', DemoSeeder::EMAIL)
            ->assertJsonPath('token_type', 'Bearer');

        $this->assertEqualsWithDelta(now()->addHours(2)->getTimestamp(), strtotime($res->json('expires_at')), 5);

        $this->withToken($res->json('token'));
        $this->getJson('/api/v1/cart')->assertOk();
        $this->getJson('/api/v1/staff/books')->assertForbidden();
    }

    public function test_it_is_404_when_there_is_no_demo_account(): void
    {
        Customer::factory()->create(['email' => 'reader@example.com']);

        $this->postJson('/api/v1/demo/token')->assertNotFound();
    }

    public function test_it_is_limited_to_ten_per_hour_per_ip(): void
    {
        Customer::factory()->create(['email' => DemoSeeder::EMAIL]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/v1/demo/token')->assertOk();
        }
        $this->postJson('/api/v1/demo/token')->assertStatus(429);
    }

    /** Owner 2026-11-27: visitors trying the docs must not create or change real orders with the shared demo account. */
    public function test_the_demo_account_cannot_place_cancel_pay_or_return_orders(): void
    {
        $demo = Customer::factory()->create(['email' => DemoSeeder::EMAIL]);
        $variant = BookVariant::factory()->create(['price_usd' => '9.90', 'stock_quantity' => 5]);
        $address = CustomerAddress::factory()->create(['customer_id' => $demo->id]);
        $this->withToken($this->postJson('/api/v1/demo/token')->assertOk()->json('token'));

        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id])->assertCreated(); // browsing and the cart stay open
        $this->postJson('/api/v1/checkout/preview', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertOk();
        $this->withHeader('Idempotency-Key', 'demo-try-1')
            ->postJson('/api/v1/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertForbidden();
        $this->assertSame(0, Order::count());
        $this->assertSame(5, $variant->fresh()->stock_quantity);

        foreach (['/api/v1/orders/1/cancel', '/api/v1/orders/1/payment/qr', '/api/v1/orders/1/payment/simulate', '/api/v1/orders/1/returns'] as $url) {
            $this->postJson($url)->assertForbidden();
        }
        $this->deleteJson('/api/v1/returns/1')->assertForbidden();
    }
}

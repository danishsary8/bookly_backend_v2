<?php

namespace Tests\Feature\Platform;

use App\Models\Customer;
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
}

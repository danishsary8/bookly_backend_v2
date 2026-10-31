<?php

namespace Tests\Feature\Customer;

use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** "Download my data": everything the shop keeps about the signed-in customer, as one JSON file. */
class DataExportTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->customer = Customer::factory()->create(['name' => 'Sok Dara', 'email' => 'dara@example.com', 'password_hash' => 'Secret123!']);
        $this->withToken($this->customer->createToken('t', ['customer'])->plainTextToken);
    }

    public function test_it_downloads_the_customers_own_data_as_a_json_file(): void
    {
        $variant = BookVariant::factory()->create(['stock_quantity' => 10]);
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => 1])->assertCreated();
        $address = CustomerAddress::factory()->for($this->customer)->create(['city' => 'Phnom Penh']);
        $this->withHeader('Idempotency-Key', 'export-test-0001')
            ->postJson('/api/v1/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertCreated();
        $this->customer->wishlistBooks()->attach($variant->book_id);
        $this->customer->notes()->create(['body' => 'Internal staff remark', 'staff_user_id' => null]);

        // Someone else's data must never appear.
        $other = Customer::factory()->create(['email' => 'other@example.com']);
        CustomerAddress::factory()->for($other)->create(['city' => 'Siem Reap']);

        $res = $this->get('/api/v1/me/export')->assertOk();

        $this->assertStringContainsString('application/json', $res->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('/attachment; filename="?bookly-my-data-\d{4}-\d{2}-\d{2}\.json"?/', $res->headers->get('Content-Disposition'));

        $data = json_decode($res->getContent(), true);
        $this->assertSame('dara@example.com', $data['profile']['email']);
        $this->assertCount(1, $data['addresses']);
        $this->assertSame('Phnom Penh', $data['addresses'][0]['city']);
        $this->assertCount(1, $data['orders']);
        $this->assertSame($variant->book_id, $data['wishlist'][0]['book_id']);
        $this->assertArrayHasKey('cart', $data);
        $this->assertArrayHasKey('reviews', $data);
        $this->assertArrayHasKey('returns', $data);
        $this->assertArrayHasKey('stock_alerts', $data);
        $this->assertArrayHasKey('store_credits', $data);
        $this->assertArrayHasKey('newsletter', $data);
        $this->assertArrayHasKey('exported_at', $data);

        $raw = json_encode($data);
        $this->assertStringNotContainsString('Siem Reap', $raw);
        $this->assertStringNotContainsString('other@example.com', $raw);
        $this->assertStringNotContainsString('password_hash', $raw);
        $this->assertStringNotContainsString('$2y$', $raw); // no bcrypt hash anywhere
        $this->assertStringNotContainsString('Internal staff remark', $raw);
    }

    public function test_guests_cannot_export(): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson('/api/v1/me/export')->assertUnauthorized();
    }

    public function test_export_is_limited_to_five_per_hour(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->get('/api/v1/me/export')->assertOk();
        }
        $this->get('/api/v1/me/export')->assertStatus(429);
    }
}

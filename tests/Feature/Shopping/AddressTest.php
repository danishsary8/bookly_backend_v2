<?php

namespace Tests\Feature\Shopping;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddressTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::factory()->create();
        $this->withToken($this->customer->createToken('t', ['customer'])->plainTextToken);
    }

    private function payload(array $extra = []): array
    {
        return ['recipient_name' => 'Sok Dara', 'phone' => '012345678', 'address_line1' => 'St 271',
            'city' => 'Phnom Penh', 'country' => 'Cambodia', ...$extra];
    }

    public function test_first_address_becomes_default_and_new_default_moves_the_flag(): void
    {
        $first = $this->postJson('/api/v1/addresses', $this->payload())->assertCreated()->assertJsonPath('data.is_default', true)->json('data.id');
        $second = $this->postJson('/api/v1/addresses', $this->payload(['label' => 'Work']))->assertCreated()->assertJsonPath('data.is_default', false)->json('data.id');

        $this->patchJson("/api/v1/addresses/{$second}", ['is_default' => true])->assertOk()->assertJsonPath('data.is_default', true);

        $this->assertFalse(CustomerAddress::find($first)->is_default);
        $this->assertSame(1, $this->customer->addresses()->where('is_default', true)->count());
        $this->getJson('/api/v1/addresses')->assertOk()->assertJsonPath('data.0.id', $second);
    }

    public function test_default_cannot_be_turned_off_directly(): void
    {
        $id = $this->postJson('/api/v1/addresses', $this->payload())->json('data.id');

        $this->patchJson("/api/v1/addresses/{$id}", ['is_default' => false])->assertOk()->assertJsonPath('data.is_default', true);
    }

    public function test_deleting_the_default_promotes_the_newest_remaining_address(): void
    {
        $default = $this->postJson('/api/v1/addresses', $this->payload())->json('data.id');
        $this->postJson('/api/v1/addresses', $this->payload());
        $newest = $this->postJson('/api/v1/addresses', $this->payload())->json('data.id');

        $this->deleteJson("/api/v1/addresses/{$default}")->assertNoContent();

        $this->assertTrue(CustomerAddress::find($newest)->is_default);
    }

    public function test_limit_of_ten_addresses(): void
    {
        CustomerAddress::factory()->count(10)->for($this->customer)->create(['is_default' => false]);

        $this->postJson('/api/v1/addresses', $this->payload())->assertUnprocessable();
    }

    public function test_customers_cannot_touch_each_others_addresses(): void
    {
        $other = CustomerAddress::factory()->create();

        $this->patchJson("/api/v1/addresses/{$other->id}", ['city' => 'Siem Reap'])->assertNotFound();
        $this->deleteJson("/api/v1/addresses/{$other->id}")->assertNotFound();
        $this->getJson('/api/v1/addresses')->assertJsonCount(0, 'data');
    }

    public function test_validation(): void
    {
        $this->postJson('/api/v1/addresses', ['city' => 'X'])->assertUnprocessable()
            ->assertJsonValidationErrors(['recipient_name', 'phone', 'address_line1', 'country']);
    }

    public function test_unverified_customer_is_blocked(): void
    {
        $token = Customer::factory()->unverified()->create()->createToken('t', ['customer'])->plainTextToken;
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/addresses')->assertForbidden();
    }
}

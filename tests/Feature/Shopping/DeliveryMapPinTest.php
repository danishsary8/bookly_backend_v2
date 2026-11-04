<?php

namespace Tests\Feature\Shopping;

use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** Owner 2026-11-04: an optional map pin on the delivery address, copied onto the order for the courier. */
class DeliveryMapPinTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private Customer $customer;

    private const ADDRESS = [
        'recipient_name' => 'Dara', 'phone' => '012345678', 'address_line1' => 'Street 240, House 12',
        'city' => 'Phnom Penh', 'country' => 'Cambodia',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->customer = Customer::factory()->create(['email_verified_at' => now()]);
        $this->asToken($this->customer->createToken('t', ['customer'])->plainTextToken);
    }

    public function test_an_address_can_have_a_pin_and_lose_it_again(): void
    {
        $id = $this->postJson('/api/v1/addresses', [...self::ADDRESS, 'latitude' => 11.556374, 'longitude' => 104.928207])
            ->assertCreated()
            ->assertJsonPath('data.latitude', 11.556374)
            ->assertJsonPath('data.longitude', 104.928207)
            ->json('data.id');

        $this->patchJson("/api/v1/addresses/{$id}", ['latitude' => null, 'longitude' => null])
            ->assertOk()->assertJsonPath('data.latitude', null)->assertJsonPath('data.longitude', null);

        // No pin at all is fine too (it's optional).
        $this->postJson('/api/v1/addresses', self::ADDRESS)->assertCreated()->assertJsonPath('data.latitude', null);
    }

    public function test_a_pin_needs_both_numbers_and_must_be_in_cambodia(): void
    {
        $this->postJson('/api/v1/addresses', [...self::ADDRESS, 'latitude' => 11.55])->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->postJson('/api/v1/addresses', [...self::ADDRESS, 'longitude' => 104.9])->assertUnprocessable()->assertJsonValidationErrors('latitude');
        // Bangkok and Paris: outside the delivery area.
        $this->postJson('/api/v1/addresses', [...self::ADDRESS, 'latitude' => 13.75, 'longitude' => 100.5])->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->postJson('/api/v1/addresses', [...self::ADDRESS, 'latitude' => 48.85, 'longitude' => 2.35])->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude']);
        $this->postJson('/api/v1/addresses', [...self::ADDRESS, 'latitude' => 'here', 'longitude' => 104.9])->assertUnprocessable();
    }

    public function test_the_order_keeps_the_pin_and_the_owner_alert_links_to_the_map(): void
    {
        config(['services.ops_alerts.token' => '123:abc', 'services.ops_alerts.chat_id' => '777']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $addressId = $this->postJson('/api/v1/addresses', [...self::ADDRESS, 'latitude' => 11.556374, 'longitude' => 104.928207])->json('data.id');
        $variant = BookVariant::factory()->create(['stock_quantity' => 10]);
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => 1])->assertCreated();

        $orderId = $this->withHeader('Idempotency-Key', 'pin-order-1')->postJson('/api/v1/checkout', ['address_id' => $addressId, 'payment_method' => 'cod'])
            ->assertCreated()->json('data.id');

        // Moving the pin later doesn't change the order (it's a copy, like the rest of the address).
        $this->patchJson("/api/v1/addresses/{$addressId}", ['latitude' => 12.0, 'longitude' => 105.0])->assertOk();

        $this->getJson("/api/v1/orders/{$orderId}")->assertOk()
            ->assertJsonPath('data.shipping_address.latitude', 11.556374)
            ->assertJsonPath('data.shipping_address.longitude', 104.928207);
        $this->asToken($this->staffToken())->getJson("/api/v1/staff/orders/{$orderId}")->assertOk()
            ->assertJsonPath('data.shipping_address.latitude', 11.556374);
        Http::assertSent(fn (Request $r) => str_contains($r['text'] ?? '', 'New order '.Order::find($orderId)->order_number)
            && str_contains($r['text'], 'Map: https://www.google.com/maps/search/?api=1&query=11.556374,104.928207'));
    }

    public function test_an_order_without_a_pin_has_none(): void
    {
        $addressId = $this->postJson('/api/v1/addresses', self::ADDRESS)->json('data.id');
        $variant = BookVariant::factory()->create(['stock_quantity' => 10]);
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => 1])->assertCreated();

        $orderId = $this->withHeader('Idempotency-Key', 'pin-order-2')->postJson('/api/v1/checkout', ['address_id' => $addressId, 'payment_method' => 'cod'])->json('data.id');

        $this->getJson("/api/v1/orders/{$orderId}")->assertOk()->assertJsonPath('data.shipping_address.latitude', null);
    }
}

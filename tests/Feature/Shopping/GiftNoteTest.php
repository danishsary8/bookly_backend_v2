<?php

namespace Tests\Feature\Shopping;

use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** Owner 2026-11-05: free "This is a gift" option with a short message, printed on the packing sheet. */
class GiftNoteTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private int $addressId;

    private static int $key = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $customer = Customer::factory()->create(['email_verified_at' => now()]);
        $this->asToken($customer->createToken('t', ['customer'])->plainTextToken);
        $this->addressId = $this->postJson('/api/v1/addresses', [
            'recipient_name' => 'Dara', 'phone' => '012345678', 'address_line1' => 'Street 240', 'city' => 'Phnom Penh', 'country' => 'Cambodia',
        ])->json('data.id');
    }

    private function checkout(array $extra = []): TestResponse
    {
        $variant = BookVariant::factory()->create(['stock_quantity' => 10]);
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => 1])->assertCreated();

        return $this->withHeader('Idempotency-Key', 'gift-order-'.(++self::$key))
            ->postJson('/api/v1/checkout', ['address_id' => $this->addressId, 'payment_method' => 'cod', ...$extra]);
    }

    public function test_a_gift_order_keeps_the_message_for_the_customer_the_packing_sheet_and_the_owner(): void
    {
        config(['services.ops_alerts.token' => '123:abc', 'services.ops_alerts.chat_id' => '777']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $id = $this->checkout(['is_gift' => true, 'gift_message' => "  Happy birthday, Sokha!\nLove, Dara  "])
            ->assertCreated()
            ->assertJsonPath('data.is_gift', true)
            ->assertJsonPath('data.gift_message', "Happy birthday, Sokha!\nLove, Dara")
            ->json('data.id');
        $total = Order::find($id)->total_amount;

        $this->getJson("/api/v1/orders/{$id}")->assertOk()->assertJsonPath('data.is_gift', true);
        $staff = $this->asToken($this->staffToken());
        $staff->getJson("/api/v1/staff/orders/{$id}")->assertOk()->assertJsonPath('data.gift_message', "Happy birthday, Sokha!\nLove, Dara");
        $staff->getJson("/api/v1/staff/orders/documents?ids[]={$id}")->assertOk()
            ->assertJsonPath('data.0.is_gift', true)->assertJsonPath('data.0.gift_message', "Happy birthday, Sokha!\nLove, Dara");

        $this->assertSame($total, Order::find($id)->total_amount); // free
        Http::assertSent(fn (Request $r) => str_contains($r['text'] ?? '', '🎁 Gift: print the packing sheet without prices — message: Happy birthday, Sokha!'));
    }

    public function test_not_a_gift_by_default_and_a_message_without_the_tick_is_dropped(): void
    {
        $this->checkout()->assertCreated()->assertJsonPath('data.is_gift', false)->assertJsonPath('data.gift_message', null);
        $this->checkout(['gift_message' => 'Hi'])->assertCreated()->assertJsonPath('data.is_gift', false)->assertJsonPath('data.gift_message', null);
        $this->checkout(['is_gift' => true, 'gift_message' => '   '])->assertCreated()->assertJsonPath('data.is_gift', true)->assertJsonPath('data.gift_message', null);
    }

    public function test_the_message_is_short(): void
    {
        $this->checkout(['is_gift' => true, 'gift_message' => str_repeat('a', 201)])->assertUnprocessable()->assertJsonValidationErrors('gift_message');
        $this->checkout(['is_gift' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('is_gift');
    }
}

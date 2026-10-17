<?php

namespace Tests\Feature\Orders;

use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class OpsAlertsTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private const TOKEN = '123456:secret-alert-token';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function enableAlerts(): void
    {
        config(['services.ops_alerts.token' => self::TOKEN, 'services.ops_alerts.chat_id' => '777']);
    }

    private function placeOrder(BookVariant $variant, int $qty = 1)
    {
        $customer = Customer::factory()->create(['name' => 'Dara']);
        $address = CustomerAddress::factory()->for($customer)->create(['city' => 'Phnom Penh']);
        $this->asToken($customer->createToken('t', ['customer'])->plainTextToken)
            ->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => $qty])->assertCreated();

        return $this->withHeader('Idempotency-Key', 'checkout-key-'.md5((string) $customer->id))->postJson('/api/v1/checkout', ['address_id' => $address->id, 'payment_method' => 'cod']);
    }

    public function test_a_new_order_sends_one_alert_to_the_owner_chat(): void
    {
        $this->enableAlerts();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $id = $this->placeOrder(BookVariant::factory()->create(['stock_quantity' => 50, 'low_stock_threshold' => 2]), 2)->assertCreated()->json('data.id');

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) use ($id) {
            $number = Order::find($id)->order_number;

            return str_contains($r->url(), '/bot'.self::TOKEN.'/sendMessage')
                && $r['chat_id'] === '777'
                && str_contains($r['text'], "New order {$number}")
                && str_contains($r['text'], 'Dara')
                && str_contains($r['text'], 'Phnom Penh');
        });
    }

    public function test_crossing_the_low_stock_threshold_sends_a_second_alert(): void
    {
        $this->enableAlerts();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->placeOrder(BookVariant::factory()->create(['stock_quantity' => 5, 'low_stock_threshold' => 4]), 2)->assertCreated();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'Low stock') && str_contains($r['text'], 'Left: 3'));
    }

    public function test_nothing_is_sent_while_the_alerts_are_not_configured(): void
    {
        config(['services.ops_alerts.token' => null, 'services.ops_alerts.chat_id' => null]);
        Http::fake();

        $this->placeOrder(BookVariant::factory()->create(['stock_quantity' => 50]))->assertCreated();

        Http::assertNothingSent();
    }

    public function test_a_telegram_outage_never_blocks_the_order_and_the_token_is_not_logged(): void
    {
        $this->enableAlerts();
        Log::spy();
        Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException('cURL error for https://api.telegram.org/bot'.self::TOKEN.'/sendMessage')]);

        $id = $this->placeOrder(BookVariant::factory()->create(['stock_quantity' => 50]))->assertCreated()->json('data.id');

        $this->assertNotNull(Order::find($id));
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context = []) => $message === 'Ops alert could not be sent' && ! str_contains(json_encode($context), self::TOKEN));
    }

    public function test_a_rejected_message_is_logged_and_the_order_still_succeeds(): void
    {
        $this->enableAlerts();
        Log::spy();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked'], 403)]);

        $this->placeOrder(BookVariant::factory()->create(['stock_quantity' => 50]))->assertCreated();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context = []) => $message === 'Ops alert was not delivered' && $context['status'] === 403);
    }

    public function test_the_staff_group_also_gets_new_orders_with_the_lines_and_a_link(): void
    {
        $this->enableAlerts();
        config(['services.ops_alerts.staff_chat_id' => '-100555', 'shop.url' => 'https://shop.example/']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $variant = BookVariant::factory()->create(['stock_quantity' => 50, 'low_stock_threshold' => 2]);
        $variant->book->update(['title' => 'Emma']);

        $id = $this->placeOrder($variant, 2)->assertCreated()->json('data.id');

        Http::assertSentCount(2);
        foreach (['777', '-100555'] as $chat) {
            Http::assertSent(fn (Request $r) => $r['chat_id'] === $chat
                && str_contains($r['text'], '• 2 × Emma (paperback)')
                && str_contains($r['text'], "Open: https://shop.example/admin/orders/{$id}"));
        }
    }

    public function test_low_stock_goes_to_the_staff_group_too_and_one_chat_never_gets_it_twice(): void
    {
        $this->enableAlerts();
        config(['services.ops_alerts.staff_chat_id' => '777']); // the owner used the same chat for both
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->placeOrder(BookVariant::factory()->create(['stock_quantity' => 3, 'low_stock_threshold' => 2]), 2)->assertCreated();

        Http::assertSentCount(2); // new order + low stock, each once
        Http::assertSent(fn (Request $r) => $r['chat_id'] === '777' && str_contains($r['text'], 'Low stock'));
    }

    public function test_only_the_staff_group_can_be_set_up(): void
    {
        config(['services.ops_alerts.token' => self::TOKEN, 'services.ops_alerts.chat_id' => null, 'services.ops_alerts.staff_chat_id' => '-100555']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->placeOrder(BookVariant::factory()->create(['stock_quantity' => 50]))->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['chat_id'] === '-100555' && ! str_contains($r['text'], 'Open:'));
    }

    public function test_new_order_alert_includes_note_when_present(): void
    {
        $this->enableAlerts();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $variant = BookVariant::factory()->create(['stock_quantity' => 50]);
        $customer = Customer::factory()->create(['name' => 'Sok']);
        $address = CustomerAddress::factory()->for($customer)->create(['city' => 'Phnom Penh']);
        $this->asToken($customer->createToken('t', ['customer'])->plainTextToken)
            ->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => 1])->assertCreated();

        $this->withHeader('Idempotency-Key', 'checkout-key-with-note')->postJson('/api/v1/checkout', [
            'address_id' => $address->id,
            'payment_method' => 'cod',
            'delivery_note' => 'Please call 012345678 before delivery',
        ])->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'Note: Please call 012345678 before delivery'));
    }

    public function test_new_order_alert_includes_recipient_phone_and_address_line(): void
    {
        $this->enableAlerts();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $variant = BookVariant::factory()->create(['stock_quantity' => 50]);
        $customer = Customer::factory()->create(['name' => 'Chann', 'phone' => '098765432']);
        $address = CustomerAddress::factory()->for($customer)->create([
            'address_line1' => 'House 45, Street 19',
            'city' => 'Phnom Penh',
            'country' => 'Cambodia',
            'phone' => '012345678',
        ]);
        $this->asToken($customer->createToken('t', ['customer'])->plainTextToken)
            ->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => 1])->assertCreated();

        $this->withHeader('Idempotency-Key', 'checkout-key-phone-addr')->postJson('/api/v1/checkout', [
            'address_id' => $address->id,
            'payment_method' => 'cod',
        ])->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r) {
            return str_contains($r['text'], 'Customer: Chann (012345678)')
                && str_contains($r['text'], 'Delivery: House 45, Street 19, Phnom Penh, Cambodia');
        });
    }

    public function test_send_test_ops_alert_command_delivers_to_configured_owner_and_staff(): void
    {
        $this->enableAlerts();
        config(['services.ops_alerts.staff_chat_id' => '-100999']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artisan('ops:test-alert')
            ->expectsOutputToContain('Testing Owner (OPS_ALERT_CHAT_ID) [ID: 777]...')
            ->expectsOutputToContain('Testing Staff Group (OPS_STAFF_CHAT_ID) [ID: -100999]...')
            ->expectsOutputToContain('Delivered successfully')
            ->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r['chat_id'] === '777' && str_contains($r['text'], '🧪 Test alert'));
        Http::assertSent(fn (Request $r) => $r['chat_id'] === '-100999' && str_contains($r['text'], '🧪 Test alert'));
    }

    public function test_send_test_ops_alert_command_warns_when_not_configured(): void
    {
        config(['services.ops_alerts.token' => null]);

        $this->artisan('ops:test-alert')
            ->expectsOutputToContain('OPS_ALERT_BOT_TOKEN is not configured.')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_send_test_ops_alert_command_targets_specific_chat(): void
    {
        $this->enableAlerts();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artisan('ops:test-alert', ['--chat' => '-100888', '--message' => 'Custom ping'])
            ->expectsOutputToContain('Testing Custom [ID: -100888]...')
            ->expectsOutputToContain('Delivered successfully')
            ->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['chat_id'] === '-100888' && str_contains($r['text'], 'Custom ping'));
    }
}

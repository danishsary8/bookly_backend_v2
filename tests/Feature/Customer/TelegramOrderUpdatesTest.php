<?php

namespace Tests\Feature\Customer;

use App\Enums\OrderStatus;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StaffUser;
use App\Services\Orders\OrderStatusService;
use App\Services\Telegram\CustomerOrderUpdates;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class TelegramOrderUpdatesTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 77889900;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.telegram_bot.token' => '123:bot-token',
            'services.telegram_bot.username' => 'BooklyBot',
            'shop.url' => 'https://bookly.test',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'api.telegram.org/bot123:bot-token/getWebhookInfo' => fn () => Http::response(['ok' => true, 'result' => [
                'url' => rtrim((string) config('app.url'), '/').'/api/v1/telegram/webhook',
                'pending_update_count' => 0,
            ]]),
            'api.telegram.org/bot123:bot-token/sendMessage' => fn () => Http::response(['ok' => true, 'result' => ['message_id' => 999]]),
        ]);
    }

    private function customerToken(Customer $customer): string
    {
        return $customer->createToken('test', ['customer'])->plainTextToken;
    }

    private function staffToken(StaffUser $staff): string
    {
        return $staff->createToken('test', ['admin'])->plainTextToken;
    }

    private function telegram(array $message): TestResponse
    {
        return $this->postJson('/api/v1/telegram/webhook', [
            'update_id' => 1,
            'message' => [
                'message_id' => 7,
                'date' => now()->timestamp,
                'chat' => ['id' => self::CHAT, 'type' => 'private'],
                'from' => ['id' => self::CHAT, 'is_bot' => false, 'first_name' => 'Sokha'],
                ...$message,
            ],
        ], [
            'X-Telegram-Bot-Api-Secret-Token' => app(TelegramBot::class)->webhookSecret(),
        ]);
    }

    public function test_customer_can_request_order_updates_telegram_link(): void
    {
        $customer = Customer::factory()->create();

        $response = $this->withToken($this->customerToken($customer))
            ->postJson('/api/v1/auth/telegram/order-updates')
            ->assertStatus(201);

        $response->assertJsonStructure(['url', 'key', 'expires_at']);
        $this->assertStringContainsString('https://t.me/BooklyBot?start=', $response->json('url'));
    }

    public function test_webhook_links_customer_chat_and_enables_order_updates(): void
    {
        $customer = Customer::factory()->create([
            'telegram_chat_id' => null,
            'telegram_order_updates' => false,
        ]);

        $linkRes = $this->withToken($this->customerToken($customer))
            ->postJson('/api/v1/auth/telegram/order-updates')
            ->assertStatus(201);

        $url = $linkRes->json('url');
        preg_match('/start=([A-Za-z0-9]+)/', $url, $matches);
        $code = $matches[1];

        // Telegram sends /start <code>
        $webhookRes = $this->telegram(['text' => "/start {$code}"])->assertOk();
        $this->assertStringContainsString('connected to Bookly order updates', $webhookRes->json('text'));

        $customer->refresh();
        $this->assertSame(self::CHAT, $customer->telegram_chat_id);
        $this->assertTrue($customer->telegram_order_updates);

        // Polling status
        $statusRes = $this->postJson('/api/v1/auth/telegram/status', ['key' => $linkRes->json('key')])
            ->assertOk();
        $statusRes->assertJsonPath('status', 'done');
        $statusRes->assertJsonPath('customer.telegram_connected', true);
        $statusRes->assertJsonPath('customer.telegram_order_updates', true);
    }

    public function test_customer_can_toggle_or_disconnect_telegram_order_updates(): void
    {
        $customer = Customer::factory()->create([
            'telegram_chat_id' => self::CHAT,
            'telegram_order_updates' => true,
        ]);

        $token = $this->customerToken($customer);

        // 1. Toggle off via PATCH /me
        $this->withToken($token)
            ->patchJson('/api/v1/me', ['telegram_order_updates' => false])
            ->assertOk()
            ->assertJsonPath('data.telegram_order_updates', false)
            ->assertJsonPath('data.telegram_connected', true);

        $this->assertFalse($customer->fresh()->telegram_order_updates);

        // 2. Disconnect completely via DELETE /me/telegram
        $this->withToken($token)
            ->deleteJson('/api/v1/me/telegram')
            ->assertOk()
            ->assertJsonPath('data.telegram_order_updates', false)
            ->assertJsonPath('data.telegram_connected', false);

        $customer->refresh();
        $this->assertNull($customer->telegram_chat_id);
        $this->assertFalse($customer->telegram_order_updates);
    }

    public function test_order_status_transitions_dispatch_telegram_messages_when_connected(): void
    {
        $customer = Customer::factory()->create([
            'telegram_chat_id' => self::CHAT,
            'telegram_order_updates' => true,
            'locale' => 'en',
        ]);
        $staff = StaffUser::factory()->admin()->create();

        $book = Book::factory()->create(['title' => 'The Great Book']);
        $variant = BookVariant::factory()->for($book)->create(['price_usd' => 15.00]);

        $order = Order::factory()->for($customer)->create([
            'order_number' => 'ORD-20261016-TEST1',
            'status' => OrderStatus::Pending,
            'total_amount' => 15.00,
        ]);
        OrderItem::factory()->for($order)->create([
            'book_variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price' => 15.00,
            'subtotal' => 15.00,
        ]);

        $service = app(OrderStatusService::class);

        // 1. Transition to Processing
        $service->changeByStaff($order, OrderStatus::Processing, $staff);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bot123:bot-token/sendMessage'
                && $request['chat_id'] === self::CHAT
                && str_contains($request['text'], 'ORD-20261016-TEST1')
                && str_contains($request['text'], 'Order Being Processed');
        });

        // 2. Transition to Shipped with note
        $service->changeByStaff($order, OrderStatus::Shipped, $staff, 'Driver: 012 999 888');
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bot123:bot-token/sendMessage'
                && $request['chat_id'] === self::CHAT
                && str_contains($request['text'], 'Order On Its Way!')
                && str_contains($request['text'], 'Driver: 012 999 888');
        });

        // 3. Transition to Delivered
        $service->changeByStaff($order, OrderStatus::Delivered, $staff);
        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bot123:bot-token/sendMessage'
                && $request['chat_id'] === self::CHAT
                && str_contains($request['text'], 'Order Delivered!');
        });
    }

    public function test_telegram_outage_does_not_break_status_transition(): void
    {
        Http::fake([
            'api.telegram.org/bot123:bot-token/sendMessage' => Http::response(['ok' => false, 'description' => 'Service Unavailable'], 503),
        ]);

        $customer = Customer::factory()->create([
            'telegram_chat_id' => self::CHAT,
            'telegram_order_updates' => true,
        ]);
        $staff = StaffUser::factory()->admin()->create();

        $order = Order::factory()->for($customer)->create([
            'order_number' => 'ORD-20261016-ERR1',
            'status' => OrderStatus::Pending,
        ]);

        $service = app(OrderStatusService::class);
        $updated = $service->changeByStaff($order, OrderStatus::Processing, $staff);

        $this->assertSame(OrderStatus::Processing, $updated->fresh()->status);
    }

    public function test_special_html_characters_are_escaped_for_telegram_message(): void
    {
        $customer = Customer::factory()->create([
            'telegram_chat_id' => self::CHAT,
            'telegram_order_updates' => true,
            'locale' => 'en',
        ]);
        $staff = StaffUser::factory()->admin()->create();

        $book = Book::factory()->create(['title' => 'Romeo & Juliet <Special Edition>']);
        $variant = BookVariant::factory()->for($book)->create(['price_usd' => 20.00]);

        $order = Order::factory()->for($customer)->create([
            'order_number' => 'ORD-20261016-HTML1',
            'status' => OrderStatus::Pending,
            'total_amount' => 20.00,
        ]);
        OrderItem::factory()->for($order)->create([
            'book_variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price' => 20.00,
            'subtotal' => 20.00,
        ]);

        $updates = app(CustomerOrderUpdates::class);
        $updates->orderPlaced($order);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bot123:bot-token/sendMessage'
                && $request['chat_id'] === self::CHAT
                && str_contains($request['text'], 'Romeo &amp; Juliet &lt;Special Edition&gt;')
                && ! str_contains($request['text'], 'Romeo & Juliet <Special Edition>');
        });

        $service = app(OrderStatusService::class);
        $service->changeByStaff($order, OrderStatus::Processing, $staff);
        $service->changeByStaff($order, OrderStatus::Shipped, $staff, 'Tracking: A & B <fast>');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bot123:bot-token/sendMessage'
                && $request['chat_id'] === self::CHAT
                && str_contains($request['text'], 'Tracking: A &amp; B &lt;fast&gt;');
        });
    }
}

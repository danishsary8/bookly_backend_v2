<?php

namespace Tests\Feature\Auth;

use App\Exceptions\TelegramBotProblem;
use App\Http\Controllers\Api\V1\TelegramWebhookController;
use App\Models\Customer;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** The free Bookly bot: "Continue with Telegram" and confirming a phone number with "Share my phone number". */
class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 5550001;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram_bot.token' => '123:bot-token', 'services.telegram_bot.username' => 'BooklyBot']);
        Http::preventStrayRequests();
        $this->webhook['url'] = $this->ourWebhook();
        Http::fake(['api.telegram.org/bot123:bot-token/getWebhookInfo' => fn () => Http::response(['ok' => true, 'result' => array_filter([
            'url' => $this->webhook['url'], 'pending_update_count' => 0,
            'last_error_date' => $this->webhook['error'] ? now()->subMinute()->timestamp : null, 'last_error_message' => $this->webhook['error'],
        ], fn ($v) => $v !== null)])]);
    }

    /** This API's webhook as tests call it (the test client uses APP_URL as its address). */
    private function ourWebhook(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/telegram/webhook';
    }

    /** @var array{url: string, error: ?string} what Telegram's getWebhookInfo answers */
    private array $webhook = ['url' => '', 'error' => null];

    private function telegramKnows(string $url, ?string $lastError = null): void
    {
        $this->webhook = ['url' => $url, 'error' => $lastError];
    }

    /** A message from Telegram to our webhook, as the customer with Telegram id CHAT. */
    private function telegram(array $message, ?string $secret = null): TestResponse
    {
        return $this->postJson('/api/v1/telegram/webhook', ['update_id' => 1, 'message' => [
            'message_id' => 7, 'date' => now()->timestamp,
            'chat' => ['id' => self::CHAT, 'type' => 'private'], 'from' => ['id' => self::CHAT, 'is_bot' => false, 'first_name' => 'Dara'],
            ...$message,
        ]], ['X-Telegram-Bot-Api-Secret-Token' => $secret ?? app(TelegramBot::class)->webhookSecret()]);
    }

    private function share(string $phone = '85512345678', ?int $userId = self::CHAT): TestResponse
    {
        return $this->telegram(['contact' => ['phone_number' => $phone, 'first_name' => 'Dara', 'user_id' => $userId]]);
    }

    /** @return array{0: string, 1: string} [t.me code, private key] */
    private function startSignIn(): array
    {
        $response = $this->postJson('/api/v1/auth/telegram')->assertCreated();
        $this->assertMatchesRegularExpression('#^https://t\.me/BooklyBot\?start=[A-Za-z0-9]{32}$#', $response->json('url'));

        return [substr($response->json('url'), -32), $response->json('key')];
    }

    private function linkStatus(string $key): TestResponse
    {
        return $this->postJson('/api/v1/auth/telegram/status', ['key' => $key]);
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create(['phone_e164' => '+85512345678', 'phone_verified_at' => now(), ...$attributes]);
    }

    public function test_continue_with_telegram_signs_the_customer_in(): void
    {
        $customer = $this->customer();
        [$code, $key] = $this->startSignIn();
        $this->linkStatus($key)->assertOk()->assertJsonPath('status', 'pending');

        $this->telegram(['text' => "/start {$code}"])->assertOk()
            ->assertJsonPath('method', 'sendMessage')
            ->assertJsonPath('chat_id', self::CHAT)
            ->assertJsonPath('reply_markup.keyboard.0.0.text', TelegramWebhookController::SHARE_BUTTON)
            ->assertJsonPath('reply_markup.keyboard.0.0.request_contact', true);
        $this->share()->assertOk()
            ->assertJsonPath('text', "✅ You're signed in. Go back to Bookly.")
            ->assertJsonPath('reply_markup.remove_keyboard', true);

        $token = $this->linkStatus($key)->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonStructure(['token', 'expires_at'])->json('token');
        $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.id', $customer->id);
        // The sign-in is handed out once.
        $this->linkStatus($key)->assertNotFound();
    }

    public function test_telegram_numbers_come_with_or_without_a_plus(): void
    {
        $this->customer();
        [$code, $key] = $this->startSignIn();
        $this->telegram(['text' => "/start {$code}"]);

        $this->share('+855 12 345 678')->assertOk();
        $this->linkStatus($key)->assertJsonPath('status', 'done');
    }

    public function test_a_number_without_an_account_is_told_so_on_both_sides(): void
    {
        [$code, $key] = $this->startSignIn();
        $this->telegram(['text' => "/start {$code}"]);

        $this->share()->assertJsonPath('text', fn (string $text) => str_contains($text, 'No Bookly account uses the number of this Telegram account (+855 12 345 678)'));
        $this->linkStatus($key)->assertOk()->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Create an account'))
            ->assertJsonMissingPath('token');
    }

    public function test_numbers_that_were_never_proven_and_closed_accounts_do_not_sign_in(): void
    {
        Customer::factory()->create(['phone' => '012 345 678', 'phone_e164' => null, 'phone_verified_at' => null]);
        $this->customer(['phone_e164' => '+85598765432'])->delete();

        foreach (['85512345678', '85598765432'] as $phone) {
            [$code, $key] = $this->startSignIn();
            $this->telegram(['text' => "/start {$code}"]);
            $this->share($phone);
            $this->linkStatus($key)->assertJsonPath('status', 'failed');
        }
    }

    public function test_a_deactivated_account_is_told_so(): void
    {
        $this->customer(['is_active' => false]);
        [$code, $key] = $this->startSignIn();
        $this->telegram(['text' => "/start {$code}"]);

        $this->share()->assertJsonPath('text', TelegramWebhookController::DEACTIVATED);
        $this->linkStatus($key)->assertJsonPath('status', 'failed')->assertJsonPath('message', TelegramWebhookController::DEACTIVATED);
    }

    public function test_someone_elses_contact_card_is_not_accepted(): void
    {
        $this->customer();
        [$code, $key] = $this->startSignIn();
        $this->telegram(['text' => "/start {$code}"]);

        $this->share('85512345678', 999)->assertJsonPath('text', fn (string $text) => str_contains($text, 'it shares your own number'));
        $this->share('85512345678', null);
        $this->linkStatus($key)->assertJsonPath('status', 'pending');
        // Their own number still works afterwards.
        $this->share()->assertOk();
        $this->linkStatus($key)->assertJsonPath('status', 'done');
    }

    public function test_only_cambodian_numbers(): void
    {
        [$code, $key] = $this->startSignIn();
        $this->telegram(['text' => "/start {$code}"]);

        $this->share('14155550100')->assertJsonPath('text', TelegramWebhookController::NOT_CAMBODIAN);
        $this->linkStatus($key)->assertJsonPath('status', 'failed');
    }

    public function test_links_expire_after_ten_minutes(): void
    {
        $this->customer();
        [$code, $key] = $this->startSignIn();
        $this->travel(11)->minutes();

        $this->telegram(['text' => "/start {$code}"])->assertJsonPath('text', TelegramWebhookController::EXPIRED);
        $this->share()->assertJsonPath('text', TelegramWebhookController::EXPIRED);
        $this->linkStatus($key)->assertNotFound();
    }

    public function test_a_used_link_cannot_be_used_again(): void
    {
        $this->customer();
        [$code] = $this->startSignIn();
        $this->telegram(['text' => "/start {$code}"]);
        $this->share();

        $this->telegram(['text' => "/start {$code}"])->assertJsonPath('text', fn (string $text) => str_contains($text, 'already been used'));
        $this->share()->assertJsonPath('text', TelegramWebhookController::EXPIRED);
    }

    public function test_only_the_browser_that_asked_can_read_the_result(): void
    {
        $this->customer();
        [$code] = $this->startSignIn();
        $this->telegram(['text' => "/start {$code}"]);
        $this->share();

        // Whoever saw the t.me link only knows the code, not the key.
        $this->linkStatus($code)->assertNotFound();
        $this->linkStatus('not-a-key')->assertNotFound();
    }

    public function test_the_webhook_only_listens_to_telegram(): void
    {
        $this->customer();
        [$code, $key] = $this->startSignIn();

        $this->telegram(['text' => "/start {$code}"], 'wrong-secret')->assertNotFound();
        $this->postJson('/api/v1/telegram/webhook', ['message' => []])->assertNotFound();
        $this->linkStatus($key)->assertJsonPath('status', 'pending');
    }

    public function test_other_messages_get_a_short_help_and_groups_are_ignored(): void
    {
        $this->telegram(['text' => 'hello'])->assertOk()->assertJsonPath('text', fn (string $text) => str_contains($text, 'open me from the Bookly website'));
        $this->telegram(['text' => '/start'])->assertOk()->assertJsonPath('text', fn (string $text) => str_contains($text, "I'm the Bookly bot"));
        $this->telegram(['text' => 'hi', 'chat' => ['id' => -100, 'type' => 'group']])->assertNoContent();
    }

    public function test_everything_is_off_without_a_bot_token(): void
    {
        config(['services.telegram_bot.token' => null]);
        $this->postJson('/api/v1/auth/telegram')->assertStatus(503);
        $this->telegram(['text' => 'hello'], 'anything')->assertNotFound();
    }

    public function test_the_bot_name_is_read_from_telegram_once_when_not_configured(): void
    {
        config(['services.telegram_bot.username' => null]);
        Http::fake(['api.telegram.org/bot123:bot-token/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1, 'is_bot' => true, 'username' => 'BooklyShopBot']])]);

        $this->postJson('/api/v1/auth/telegram')->assertCreated()->assertJsonPath('url', fn (string $url) => str_starts_with($url, 'https://t.me/BooklyShopBot?start='));
        $this->postJson('/api/v1/auth/telegram')->assertCreated();
        Http::assertSentCount(2); // getMe once, getWebhookInfo once (checked every 10 minutes)
    }

    public function test_a_wrong_bot_token_is_reported_and_the_customer_told_to_use_another_way(): void
    {
        Exceptions::fake();
        config(['services.telegram_bot.username' => null]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized'], 401)]);

        $this->postJson('/api/v1/auth/telegram')->assertStatus(503)->assertJsonPath('message', fn (string $m) => str_contains($m, 'Use your email'));
        Exceptions::assertReported(fn (TelegramBotProblem $e) => str_contains($e->getMessage(), 'TELEGRAM_BOT_TOKEN') && ! str_contains($e->getMessage(), 'bot-token'));
    }

    public function test_a_signed_in_customer_confirms_their_number(): void
    {
        $customer = Customer::factory()->unverified()->create(['phone' => null]);
        $token = $customer->createToken('t', ['customer'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/auth/telegram/phone')->assertCreated();
        $this->telegram(['text' => '/start '.substr($response->json('url'), -32)])
            ->assertJsonPath('text', fn (string $text) => str_starts_with($text, 'Confirm your phone number for Bookly'));
        $this->share()->assertJsonPath('text', '✅ +855 12 345 678 is now the verified number of your Bookly account. Go back to Bookly.');

        $this->linkStatus($response->json('key'))->assertOk()
            ->assertJsonPath('status', 'done')
            ->assertJsonPath('customer.phone_number', '+85512345678')
            ->assertJsonPath('customer.phone_verified', true)
            ->assertJsonPath('customer.verified', true)
            ->assertJsonMissingPath('token');
        $this->assertSame('+855 12 345 678', $customer->fresh()->phone);
    }

    public function test_changing_to_a_number_another_account_has_is_refused(): void
    {
        $this->customer();
        $customer = Customer::factory()->create(['phone_e164' => '+85598765432', 'phone_verified_at' => now()]);

        $response = $this->withToken($customer->createToken('t', ['customer'])->plainTextToken)->postJson('/api/v1/auth/telegram/phone')->assertCreated();
        $this->telegram(['text' => '/start '.substr($response->json('url'), -32)]);
        $this->share()->assertJsonPath('text', fn (string $text) => str_contains($text, 'already on another Bookly account'));

        $this->linkStatus($response->json('key'))->assertJsonPath('status', 'failed');
        $this->assertSame('+85598765432', $customer->fresh()->phone_e164);
    }

    public function test_confirming_needs_a_signed_in_customer(): void
    {
        $this->postJson('/api/v1/auth/telegram/phone')->assertUnauthorized();
    }

    public function test_the_container_points_telegram_at_the_webhook(): void
    {
        config(['app.url' => 'https://bookly-api.example.com']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        $this->artisan('telegram:webhook')->assertSuccessful();

        Http::assertSent(fn ($request) => $request->url() === 'https://api.telegram.org/bot123:bot-token/setWebhook'
            && $request['url'] === 'https://bookly-api.example.com/api/v1/telegram/webhook'
            && $request['secret_token'] === app(TelegramBot::class)->webhookSecret()
            && $request['allowed_updates'] === ['message']);
    }

    public function test_a_failed_webhook_setup_is_reported(): void
    {
        Exceptions::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: bad webhook: HTTPS url must be provided for webhook'], 400)]);

        $this->artisan('telegram:webhook')->assertFailed();
        Exceptions::assertReported(fn (TelegramBotProblem $e) => str_contains($e->getMessage(), 'HTTPS url must be provided'));
    }

    public function test_without_a_token_the_command_does_nothing(): void
    {
        config(['services.telegram_bot.token' => null]);

        $this->artisan('telegram:webhook')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_a_missing_or_wrong_webhook_is_set_to_this_apis_address(): void
    {
        // Live (2026-10-08): the bot never answered Start because Telegram didn't have our address.
        $this->telegramKnows('');
        Http::fake(['api.telegram.org/bot123:bot-token/setWebhook' => Http::response(['ok' => true, 'result' => true])]);

        $this->postJson('/api/v1/auth/telegram')->assertCreated();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === $this->ourWebhook()
            && $request['secret_token'] === app(TelegramBot::class)->webhookSecret());
    }

    public function test_the_webhook_is_checked_at_most_every_ten_minutes(): void
    {
        $this->postJson('/api/v1/auth/telegram')->assertCreated();
        $this->postJson('/api/v1/auth/telegram')->assertCreated();
        Http::assertSentCount(1);

        $this->travel(11)->minutes();
        $this->postJson('/api/v1/auth/telegram')->assertCreated();
        Http::assertSentCount(2);
    }

    public function test_telegrams_delivery_errors_reach_the_owner(): void
    {
        Exceptions::fake();
        $this->telegramKnows($this->ourWebhook(), 'Wrong response from the webhook: 404 Not Found');

        $this->postJson('/api/v1/auth/telegram')->assertCreated();

        Exceptions::assertReported(fn (TelegramBotProblem $e) => str_contains($e->getMessage(), "couldn't deliver the bot's messages to ".$this->ourWebhook())
            && str_contains($e->getMessage(), '404 Not Found'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/setWebhook'));
    }

    public function test_the_link_still_works_when_the_check_fails(): void
    {
        Exceptions::fake();
        Http::fake(['api.telegram.org/*' => fn () => throw new ConnectionException('timed out')]);

        $this->postJson('/api/v1/auth/telegram')->assertCreated();
        Exceptions::assertReported(TelegramBotProblem::class);
    }
}

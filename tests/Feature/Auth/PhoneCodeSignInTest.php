<?php

namespace Tests\Feature\Auth;

use App\Http\Controllers\Api\V1\Customer\PhoneCodeController;
use App\Http\Controllers\Api\V1\TelegramWebhookController;
use App\Models\Customer;
use App\Notifications\AccountSecurityNotice;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Sign in with a phone number and a 6-digit code that the Bookly Telegram bot sends. */
class PhoneCodeSignInTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 5550002;

    private const PHONE = '+85512345678';

    /** @var list<array<string, mixed>> what the bot sent */
    private array $sent = [];

    private bool $blocked = false;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.telegram_bot.token' => '123:bot-token', 'services.telegram_bot.username' => 'BooklyBot']);
        Http::preventStrayRequests();
        Http::fake(['api.telegram.org/bot123:bot-token/sendMessage' => function (SentRequest $request) {
            if ($this->blocked) {
                return Http::response(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403);
            }
            $this->sent[] = $request->data();

            return Http::response(['ok' => true, 'result' => ['message_id' => count($this->sent)]]);
        }]);
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::factory()->create([
            'phone' => '012345678', 'phone_e164' => self::PHONE, 'phone_verified_at' => now(), 'telegram_chat_id' => self::CHAT,
            ...$attributes,
        ]);
    }

    private function ask(string $phone = '012 345 678'): TestResponse
    {
        return $this->postJson('/api/v1/auth/phone/code', ['phone' => $phone]);
    }

    private function signIn(string $code, string $phone = '012345678'): TestResponse
    {
        return $this->postJson('/api/v1/auth/phone/verify', ['phone' => $phone, 'code' => $code]);
    }

    /** The code in the bot's latest message. */
    private function codeFromTelegram(): string
    {
        $this->assertNotEmpty($this->sent, 'the bot sent nothing');
        $this->assertSame(1, preg_match('#<code>(\d{6})</code>#', (string) end($this->sent)['text'], $m));

        return $m[1];
    }

    public function test_signs_in_with_the_code_the_bot_sends(): void
    {
        $customer = $this->customer();

        $this->ask()->assertStatus(202)->assertExactJson(['message' => PhoneCodeController::SENT, 'expires_in_minutes' => 10]);

        $this->assertCount(1, $this->sent);
        $this->assertSame(self::CHAT, $this->sent[0]['chat_id']);
        $this->assertSame('HTML', $this->sent[0]['parse_mode']);
        $this->assertStringContainsString('Never share it', $this->sent[0]['text']);

        $response = $this->signIn($this->codeFromTelegram())->assertOk()
            ->assertJsonPath('customer.id', $customer->id)
            ->assertJsonPath('token_type', 'Bearer');
        $this->withToken($response->json('token'))->getJson('/api/v1/me')->assertOk();

        // The chat hears about it and can undo it; the account's email gets a security notice.
        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString('signed in to Bookly with a code', $this->sent[1]['text']);
        $this->assertSame(TelegramWebhookController::SIGN_OUT_BUTTON, $this->sent[1]['reply_markup']['keyboard'][0][0]['text']);
        Notification::assertSentTo($customer, AccountSecurityNotice::class);
    }

    public function test_sign_me_out_everywhere_in_the_bot_ends_a_code_sign_in(): void
    {
        $customer = $this->customer();
        $this->ask();
        $this->signIn($this->codeFromTelegram())->assertOk();
        $this->assertSame(1, $customer->tokens()->count());

        $this->postJson('/api/v1/telegram/webhook', ['update_id' => 2, 'message' => [
            'message_id' => 9, 'date' => now()->timestamp, 'text' => TelegramWebhookController::SIGN_OUT_BUTTON,
            'chat' => ['id' => self::CHAT, 'type' => 'private'], 'from' => ['id' => self::CHAT, 'is_bot' => false, 'first_name' => 'Dara'],
        ]], ['X-Telegram-Bot-Api-Secret-Token' => app(TelegramBot::class)->webhookSecret()])
            ->assertOk()->assertJsonPath('text', fn (string $text) => str_contains($text, 'every device was signed out'));

        $this->assertSame(0, $customer->tokens()->count());
    }

    public function test_gives_the_same_answer_whatever_the_number_and_sends_nothing_it_should_not(): void
    {
        $this->customer(['phone_e164' => '+85512000001', 'phone' => '012000001', 'telegram_chat_id' => null]); // never opened the bot
        $this->customer(['phone_e164' => '+85512000002', 'phone' => '012000002', 'phone_verified_at' => null, 'telegram_chat_id' => 1]); // not confirmed
        $this->customer(['phone_e164' => '+85512000003', 'phone' => '012000003', 'is_active' => false, 'telegram_chat_id' => 2]); // deactivated
        $closed = $this->customer(['phone_e164' => '+85512000004', 'phone' => '012000004', 'telegram_chat_id' => 3]);
        $closed->delete();

        foreach (['012 999 999', '012000001', '012000002', '012000003', '012000004'] as $phone) {
            $this->ask($phone)->assertStatus(202)->assertExactJson(['message' => PhoneCodeController::SENT, 'expires_in_minutes' => 10]);
        }

        $this->assertSame([], $this->sent);
        $this->assertDatabaseCount('verification_tokens', 0);
        $this->signIn('123456', '012999999')->assertStatus(422)->assertJsonPath('message', PhoneCodeController::WRONG);
    }

    public function test_only_cambodian_numbers(): void
    {
        $this->ask('+66 81 234 5678')->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->ask('hello')->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson('/api/v1/auth/phone/code', [])->assertStatus(422)->assertJsonValidationErrors('phone');
    }

    public function test_one_code_a_minute_and_five_an_hour_per_number_whoever_asks(): void
    {
        $this->customer();
        $this->ask()->assertStatus(202);
        $this->ask('+855 12 345 678')->assertStatus(429)->assertHeader('Retry-After')
            ->assertJsonPath('message', 'A code was just sent to this number. Please wait a minute before asking for another.');
        $this->ask('012 999 999')->assertStatus(202); // another number is not held up

        for ($i = 0; $i < 4; $i++) {
            $this->travel(61)->seconds();
            $this->ask()->assertStatus(202);
        }
        $this->travel(61)->seconds();
        $this->ask()->assertStatus(429)->assertJsonPath('message', fn (string $m) => str_contains($m, 'Continue with Telegram'));
        $this->assertCount(5, $this->sent);
    }

    public function test_a_wrong_code_fails_and_five_wrong_guesses_throw_the_code_away(): void
    {
        $this->customer();
        $this->ask();
        $code = $this->codeFromTelegram();
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->signIn($wrong)->assertStatus(422)->assertJsonValidationErrors('code');
        for ($i = 0; $i < 4; $i++) {
            $this->travel(61)->seconds(); // past the 5-a-minute guess limit: only the code's own count stops it
            $this->signIn($wrong)->assertStatus(422);
        }
        $this->travel(61)->seconds();
        $this->signIn($code)->assertStatus(422)->assertJsonPath('message', PhoneCodeController::WRONG);
    }

    public function test_a_new_code_replaces_the_old_one_and_codes_expire_after_ten_minutes(): void
    {
        $this->customer();
        $this->ask();
        $first = $this->codeFromTelegram();
        $this->travel(61)->seconds();
        $this->ask();
        $second = $this->codeFromTelegram();

        if ($first !== $second) {
            $this->signIn($first)->assertStatus(422);
        }
        $this->travel(11)->minutes();
        $this->signIn($second)->assertStatus(422);
    }

    public function test_guessing_is_limited_per_number_and_device(): void
    {
        $this->customer();
        for ($i = 0; $i < 5; $i++) {
            $this->signIn('000000')->assertStatus(422);
        }
        $this->signIn('000000')->assertStatus(429);
    }

    public function test_says_so_when_the_bot_is_off(): void
    {
        config(['services.telegram_bot.token' => '']);
        $this->ask()->assertStatus(503);
    }

    public function test_a_customer_who_blocked_the_bot_gets_the_same_answer_and_nothing_is_reported(): void
    {
        Exceptions::fake();
        $this->customer();
        $this->blocked = true;

        $this->ask()->assertStatus(202)->assertJsonPath('message', PhoneCodeController::SENT);

        Exceptions::assertNothingReported();
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\VerificationToken;
use App\Notifications\OtpCodeNotification;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

/**
 * Owner (2026-10-08): phone numbers are proven in the free Bookly Telegram bot. Signing up with Telegram needs
 * no email and no typed number; new Facebook customers confirm their number there or give an email.
 */
class TelegramSignUpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.telegram_bot.token' => '123:bot-token', 'services.telegram_bot.username' => 'BooklyBot']);
        // Telegram already delivers the bot's messages here (checked when a link starts).
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['url' => rtrim((string) config('app.url'), '/').'/api/v1/telegram/webhook']])]);
    }

    private function register(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', [
            'name' => 'Sok Dara', 'password' => 'secret123', 'password_confirmation' => 'secret123', 'verify_by' => 'telegram', ...$overrides,
        ]);
    }

    /** The customer taps "Open Telegram", then "Share my phone number" in the bot; returns the website's last status. */
    private function confirmInTelegram(string $token, string $phone = '85512345678'): TestResponse
    {
        $link = $this->withToken($token)->postJson('/api/v1/auth/telegram/phone')->assertCreated();
        $telegram = fn (array $message) => $this->postJson('/api/v1/telegram/webhook', ['message' => [
            'chat' => ['id' => 42, 'type' => 'private'], 'from' => ['id' => 42], ...$message,
        ]], ['X-Telegram-Bot-Api-Secret-Token' => app(TelegramBot::class)->webhookSecret()])->assertOk();
        $telegram(['text' => '/start '.substr($link->json('url'), -32)]);
        $telegram(['contact' => ['phone_number' => $phone, 'user_id' => 42]]);

        return $this->postJson('/api/v1/auth/telegram/status', ['key' => $link->json('key')])->assertOk();
    }

    public function test_options_say_whether_the_telegram_bot_is_on(): void
    {
        $this->getJson('/api/v1/auth/options')->assertOk()->assertExactJson(['telegram' => true]);
        config(['services.telegram_bot.token' => null]);
        $this->getJson('/api/v1/auth/options')->assertExactJson(['telegram' => false]);
    }

    public function test_a_customer_signs_up_with_telegram_and_no_email(): void
    {
        $token = $this->register()->assertCreated()
            ->assertJsonPath('verify_by', 'telegram')
            ->assertJsonPath('message', 'Account created. Confirm your phone number in Telegram.')
            ->assertJsonPath('customer.email', null)
            ->assertJsonPath('customer.verified', false)
            ->json('token');
        Notification::assertNothingSent();
        $this->withToken($token)->getJson('/api/v1/cart')->assertForbidden();

        $this->confirmInTelegram($token)->assertJsonPath('status', 'done')
            ->assertJsonPath('customer.verified', true)
            ->assertJsonPath('customer.phone_number', '+85512345678')
            ->assertJsonPath('customer.phone', '+855 12 345 678');

        $this->app['auth']->forgetGuards(); // the test keeps the first request's customer in memory
        $this->withToken($token)->getJson('/api/v1/cart')->assertOk();
        // Without an email the password can't be typed anywhere; Telegram is their way in.
        $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('data.sign_in_methods', ['telegram']);
    }

    public function test_an_email_given_with_telegram_is_kept_but_gets_no_code(): void
    {
        $this->register(['email' => 'Dara@Example.com'])->assertCreated()
            ->assertJsonPath('customer.email', 'dara@example.com')
            ->assertJsonPath('customer.email_verified', false);
        $this->register(['email' => ''])->assertCreated()->assertJsonPath('customer.email', null);
        Notification::assertNothingSent();
    }

    public function test_a_typed_number_is_not_saved_with_telegram(): void
    {
        // Only the number Telegram shares is proven; an old website sending one changes nothing.
        $this->register(['phone' => '012 345 678'])->assertCreated()->assertJsonPath('customer.phone', null);
    }

    public function test_the_number_shared_must_not_belong_to_another_account(): void
    {
        Customer::factory()->create(['phone_e164' => '+85512345678', 'phone_verified_at' => now()]);
        $token = $this->register()->json('token');

        $this->confirmInTelegram($token)->assertJsonPath('status', 'failed')
            ->assertJsonPath('message', 'This number is already on another Bookly account. Sign in with Telegram instead.');
        $this->assertFalse(Customer::latest('id')->first()->isVerified());
    }

    public function test_telegram_sign_up_is_refused_while_the_bot_is_off(): void
    {
        config(['services.telegram_bot.token' => null]);

        $this->register()->assertUnprocessable()->assertJsonValidationErrors('verify_by');
        $this->assertSame(0, Customer::count());
    }

    public function test_email_sign_ups_still_need_an_email_and_get_a_code(): void
    {
        $this->register(['verify_by' => 'email'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->register(['verify_by' => 'email', 'email' => 'sok@example.com', 'phone' => '012 345 678'])->assertCreated()
            ->assertJsonPath('verify_by', 'email')
            ->assertJsonPath('customer.phone', '012 345 678');
        Notification::assertSentTo(Customer::first(), OtpCodeNotification::class);
    }

    public function test_an_email_taken_by_another_account_is_still_refused(): void
    {
        Customer::factory()->create(['email' => 'taken@example.com']);

        $this->register(['email' => 'taken@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_a_code_email_needs_an_email(): void
    {
        $token = $this->register()->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/resend-verification')->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_unfinished_telegram_sign_ups_are_cleaned_up_after_48_hours_and_confirmed_ones_stay(): void
    {
        $this->register()->assertCreated();
        $this->confirmInTelegram($this->register(['name' => 'Kept'])->json('token'));
        $this->travel(49)->hours();

        $this->artisan('customers:prune-unverified')->assertSuccessful();
        $this->assertSame(['Kept'], Customer::pluck('name')->all());
        $this->assertSame(0, VerificationToken::count());
    }

    // Facebook: a new account confirms a phone number in Telegram, or gives an email.

    private function facebook(?string $email): void
    {
        Http::fake(['graph.facebook.com/debug_token*' => Http::response(['data' => ['app_id' => '4242', 'is_valid' => true]])]);
        config(['services.facebook.client_id' => '4242', 'services.facebook.client_secret' => 'fb-secret']);
        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('userFromToken')->andReturn((new SocialiteUser)->map(['id' => 'fb-7', 'email' => $email, 'name' => 'Sok Dara']));
        Socialite::shouldReceive('driver')->with('facebook')->andReturn($driver);
    }

    private function facebookSignIn(array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/social/facebook', ['access_token' => 'fb-token', ...$extra]);
    }

    public function test_new_facebook_customers_are_asked_how_to_confirm_first(): void
    {
        $this->facebook('sok@gmail.com');

        $this->facebookSignIn()->assertUnprocessable()
            ->assertJsonPath('needs', 'phone')
            ->assertJsonPath('profile', ['name' => 'Sok Dara', 'email' => 'sok@gmail.com'])
            ->assertJsonPath('telegram', true);
        $this->assertSame(0, Customer::count());
    }

    public function test_facebook_with_telegram_confirms_the_number_in_the_bot(): void
    {
        $this->facebook(null);

        $token = $this->facebookSignIn(['verify_by' => 'telegram'])->assertCreated()
            ->assertJsonPath('verify_by', 'telegram')
            ->assertJsonPath('customer.email', null)
            ->assertJsonPath('customer.verified', false)
            ->json('token');
        $this->confirmInTelegram($token)->assertJsonPath('customer.verified', true);

        // Next time Facebook signs them straight in.
        $this->facebookSignIn()->assertOk()->assertJsonPath('customer.phone_number', '+85512345678');
        Notification::assertNothingSent();
    }

    public function test_facebook_with_its_own_email_is_verified_at_once(): void
    {
        $this->facebook('sok@gmail.com');

        $this->facebookSignIn(['verify_by' => 'telegram', 'email' => 'SOK@gmail.com'])->assertCreated()
            ->assertJsonPath('verify_by', 'telegram')
            ->assertJsonPath('customer.verified', true);
        Customer::query()->forceDelete();

        $this->facebookSignIn(['verify_by' => 'email', 'email' => 'sok@gmail.com'])->assertCreated()
            ->assertJsonPath('verify_by', null)
            ->assertJsonPath('customer.email_verified', true);
        Notification::assertNothingSent();
    }

    public function test_facebook_without_telegram_gets_an_email_code(): void
    {
        $this->facebook(null);

        $this->facebookSignIn(['verify_by' => 'email'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->facebookSignIn(['verify_by' => 'email', 'email' => 'mine@example.com'])->assertCreated()
            ->assertJsonPath('verify_by', 'email')
            ->assertJsonPath('customer.email_verified', false);
        Notification::assertSentTo(Customer::first(), OtpCodeNotification::class);
    }

    public function test_facebook_cannot_choose_telegram_while_the_bot_is_off(): void
    {
        $this->facebook(null);
        config(['services.telegram_bot.token' => null]);

        $this->facebookSignIn()->assertJsonPath('telegram', false);
        $this->facebookSignIn(['verify_by' => 'telegram'])->assertUnprocessable()->assertJsonValidationErrors('verify_by');
        $this->assertSame(0, Customer::count());
    }

    public function test_facebook_sign_up_refuses_an_email_another_account_has(): void
    {
        $this->facebook(null);
        Customer::factory()->create(['email' => 'taken@example.com']);

        $this->facebookSignIn(['verify_by' => 'email', 'email' => 'taken@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(1, Customer::count());
    }

    public function test_a_verified_phone_is_a_way_in_while_the_bot_is_on(): void
    {
        $customer = Customer::factory()->create(['phone_e164' => '+85512345678', 'phone_verified_at' => now(), 'password_hash' => null, 'google_id' => 'g-1']);
        $token = $customer->createToken('t', ['customer'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('data.sign_in_methods', ['google', 'telegram']);
        // So Google can go: Telegram still gets them in.
        $this->withToken($token)->deleteJson('/api/v1/me/connections/google')->assertOk();

        config(['services.telegram_bot.token' => null]);
        $this->withToken($token)->getJson('/api/v1/me')->assertJsonPath('data.sign_in_methods', []);
    }
}

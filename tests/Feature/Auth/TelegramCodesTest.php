<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\VerificationToken;
use App\Notifications\OtpCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class TelegramCodesTest extends TestCase
{
    use RefreshDatabase;

    private const GATEWAY = 'gatewayapi.telegram.org/*';

    /** @var list<string> codes Telegram was asked to deliver */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.telegram_gateway.token' => 'gateway-token']);
    }

    /** Telegram Gateway answers like the real one; remembers the codes so tests can type them. */
    private function gateway(?string $error = null): void
    {
        Http::fake([self::GATEWAY => function (HttpRequest $request) use ($error) {
            if ($error !== null) {
                return Http::response(['ok' => false, 'error' => $error]);
            }
            $this->sent[] = $request['code'];

            return Http::response(['ok' => true, 'result' => ['request_id' => 'r1', 'phone_number' => $request['phone_number'], 'request_cost' => 0.01]]);
        }]);
    }

    private function register(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', [
            'name' => 'Sok Dara', 'email' => 'sok@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123',
            'verify_by' => 'telegram', 'phone' => '012 345 678', ...$overrides,
        ]);
    }

    public function test_options_say_whether_telegram_codes_are_on(): void
    {
        $this->getJson('/api/v1/auth/options')->assertOk()->assertExactJson(['telegram_codes' => true]);
        config(['services.telegram_gateway.token' => null]);
        $this->getJson('/api/v1/auth/options')->assertExactJson(['telegram_codes' => false]);
    }

    public function test_signing_up_with_telegram_sends_the_code_there_and_the_code_verifies_the_account(): void
    {
        $this->gateway();

        $token = $this->register()->assertCreated()
            ->assertJsonPath('verify_by', 'telegram')
            ->assertJsonPath('customer.verified', false)
            ->assertJsonPath('customer.phone', '+855 12 345 678')
            ->json('token');

        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer gateway-token')
            && $r['phone_number'] === '+85512345678' && $r['ttl'] === 900 && strlen($r['code']) === 6);
        Notification::assertNothingSent();
        $this->withToken($token)->getJson('/api/v1/cart')->assertForbidden();

        $this->withToken($token)->postJson('/api/v1/auth/verify-phone', ['code' => '000000'])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/auth/verify-phone', ['code' => $this->sent[0]])->assertOk()
            ->assertJsonPath('customer.verified', true)
            ->assertJsonPath('customer.phone_verified', true)
            ->assertJsonPath('customer.email_verified', false)
            ->assertJsonPath('customer.phone_number', '+85512345678');

        $this->withToken($token)->getJson('/api/v1/cart')->assertOk();
    }

    public function test_nothing_is_created_when_telegram_cannot_deliver(): void
    {
        $this->gateway('PHONE_NUMBER_NOT_FOUND');
        $this->register()->assertUnprocessable()->assertJsonValidationErrors(['phone' => 'Check it has Telegram']);

        config(['services.telegram_gateway.token' => null]);
        $this->register()->assertUnprocessable()->assertJsonValidationErrors(['phone' => 'use email instead']);

        $this->assertSame(0, Customer::count());
    }

    public function test_only_cambodian_numbers_are_accepted(): void
    {
        $this->gateway();
        $this->register(['phone' => '+1 202 555 0143'])->assertUnprocessable()->assertJsonValidationErrors(['phone' => 'Cambodian']);
        $this->register(['phone' => ''])->assertUnprocessable()->assertJsonValidationErrors('phone');
        Http::assertNothingSent();
    }

    public function test_a_verified_number_belongs_to_one_account(): void
    {
        $this->gateway();
        Customer::factory()->create(['phone_e164' => '+85512345678', 'phone_verified_at' => now()]);

        $this->register()->assertUnprocessable()->assertJsonValidationErrors(['phone' => 'already on another']);
        Http::assertNothingSent();
    }

    public function test_a_customer_can_ask_for_a_new_code_as_often_as_they_need(): void
    {
        // Owner (2026-10-08): no cap per number by default; a missing code is often our side, not theirs.
        $this->gateway();
        $token = $this->register()->assertCreated()->json('token');
        foreach ([1, 2, 3, 4] as $_) {
            $this->travel(4)->minutes(); // past the per-visitor limit (3 sends per 10 minutes)
            $this->withToken($token)->postJson('/api/v1/auth/resend-verification', ['channel' => 'telegram'])->assertOk();
        }

        $this->assertCount(5, $this->sent);
    }

    public function test_a_cap_per_number_can_be_switched_on(): void
    {
        config(['auth.otp.phone_codes_per_hour' => 3]);
        $this->gateway();
        $token = $this->register()->assertCreated()->json('token');
        foreach ([1, 2] as $_) {
            $this->withToken($token)->postJson('/api/v1/auth/resend-verification', ['channel' => 'telegram'])->assertOk();
            $this->travel(4)->minutes();
        }

        $this->withToken($token)->postJson('/api/v1/auth/resend-verification', ['channel' => 'telegram'])
            ->assertUnprocessable()->assertJsonValidationErrors(['phone' => '3 codes in the last hour']);
        $this->assertCount(3, $this->sent);
    }

    public function test_codes_telegram_could_not_send_never_count_against_the_cap(): void
    {
        config(['auth.otp.phone_codes_per_hour' => 1]);
        Http::fake([self::GATEWAY => Http::sequence()
            ->push(['ok' => false, 'error' => 'BALANCE_NOT_ENOUGH']) // our side: nothing reached the customer
            ->push(['ok' => true, 'result' => ['request_id' => 'r1']])
            ->push(['ok' => true, 'result' => ['request_id' => 'r2']])]);

        $this->register()->assertUnprocessable()->assertJsonValidationErrors('phone');
        $token = $this->register()->assertCreated()->json('token'); // the failed send didn't use up the one code
        $this->travel(11)->minutes();
        $this->withToken($token)->postJson('/api/v1/auth/resend-verification', ['channel' => 'telegram'])
            ->assertUnprocessable()->assertJsonValidationErrors(['phone' => '1 code in the last hour']);
    }

    public function test_send_it_by_email_instead(): void
    {
        $this->gateway();
        $token = $this->register()->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/resend-verification', ['channel' => 'email'])->assertOk();

        $customer = Customer::first();
        Notification::assertSentTo($customer, OtpCodeNotification::class, function ($n) use ($token) {
            $this->withToken($token)->postJson('/api/v1/auth/verify-email', ['code' => $n->code])->assertOk();

            return true;
        });
        $this->assertTrue($customer->fresh()->isVerified());
    }

    public function test_changing_the_number_keeps_the_old_one_until_the_new_code_is_entered(): void
    {
        $this->gateway();
        $customer = Customer::factory()->create(['phone_e164' => '+85512345678', 'phone_verified_at' => now(), 'email_verified_at' => null]);
        $token = $customer->createToken('t', ['customer'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/phone', ['phone' => '087 860 999'])->assertOk()
            ->assertJsonPath('phone', '+855 87 860 999');
        $this->assertSame('+85512345678', $customer->fresh()->phone_e164);
        $this->withToken($token)->getJson('/api/v1/cart')->assertOk(); // still verified meanwhile

        $this->withToken($token)->postJson('/api/v1/auth/verify-phone', ['code' => $this->sent[0]])->assertOk()
            ->assertJsonPath('customer.phone_number', '+85587860999');
    }

    public function test_phone_verified_customers_count_as_verified_for_staff_and_the_clean_up(): void
    {
        Customer::factory()->unverified()->create(['phone_e164' => '+85512345678', 'phone_verified_at' => now(), 'created_at' => now()->subDays(3)]);
        Customer::factory()->unverified()->create(['created_at' => now()->subDays(3)]);

        $this->artisan('customers:prune-unverified')->assertSuccessful();

        $this->assertSame(1, Customer::count());
        $this->assertNotNull(Customer::first()->phone_e164);
        $this->assertSame(0, VerificationToken::count());
    }

    // Facebook: a new account needs a phone number; the email is optional.

    private function facebook(?string $email): void
    {
        Http::fake([
            'graph.facebook.com/debug_token*' => Http::response(['data' => ['app_id' => '4242', 'is_valid' => true]]),
        ]);
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

    public function test_new_facebook_customers_are_asked_for_their_phone_first(): void
    {
        $this->facebook('sok@gmail.com');
        $this->gateway();

        $this->facebookSignIn()->assertUnprocessable()
            ->assertJsonPath('needs', 'phone')
            ->assertJsonPath('profile', ['name' => 'Sok Dara', 'email' => 'sok@gmail.com'])
            ->assertJsonPath('telegram_codes', true);
        $this->assertSame(0, Customer::count());
    }

    public function test_facebook_with_its_email_is_verified_at_once_and_the_phone_gets_a_code(): void
    {
        $this->facebook('sok@gmail.com');
        $this->gateway();

        $this->facebookSignIn(['phone' => '012 345 678', 'email' => 'SOK@gmail.com'])->assertCreated()
            ->assertJsonPath('verify_by', 'telegram')
            ->assertJsonPath('customer.verified', true)
            ->assertJsonPath('customer.email', 'sok@gmail.com')
            ->assertJsonPath('customer.phone_verified', false);
        $this->assertCount(1, $this->sent);
    }

    public function test_facebook_without_email_must_prove_the_phone(): void
    {
        $this->facebook(null);
        $this->gateway();

        $token = $this->facebookSignIn(['phone' => '012 345 678'])->assertCreated()
            ->assertJsonPath('verify_by', 'telegram')
            ->assertJsonPath('customer.email', null)
            ->assertJsonPath('customer.verified', false)
            ->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/verify-phone', ['code' => $this->sent[0]])->assertOk()
            ->assertJsonPath('customer.verified', true);

        // Next time Facebook signs them straight in.
        $this->facebookSignIn()->assertOk()->assertJsonPath('customer.phone_number', '+85512345678');
    }

    public function test_facebook_without_email_or_telegram_has_to_add_an_email(): void
    {
        $this->facebook(null);
        config(['services.telegram_gateway.token' => null]);

        $this->facebookSignIn(['phone' => '012 345 678'])->assertUnprocessable()
            ->assertJsonValidationErrors(['phone' => 'Add your email']);
        $this->assertSame(0, Customer::count());

        $this->facebookSignIn(['phone' => '012 345 678', 'email' => 'mine@example.com'])->assertCreated()
            ->assertJsonPath('verify_by', 'email')
            ->assertJsonPath('customer.email_verified', false);
        Notification::assertSentTo(Customer::first(), OtpCodeNotification::class);
    }

    public function test_facebook_sign_up_refuses_a_taken_phone_or_email(): void
    {
        $this->facebook(null);
        $this->gateway();
        Customer::factory()->create(['email' => 'taken@example.com', 'phone_e164' => '+85512345678', 'phone_verified_at' => now()]);

        $this->facebookSignIn(['phone' => '012 345 678'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->facebookSignIn(['phone' => '087 860 999', 'email' => 'taken@example.com'])->assertUnprocessable()->assertJsonValidationErrors('email');
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'gatewayapi.telegram.org'));
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Enums\VerificationPurpose;
use App\Models\Customer;
use App\Notifications\OtpCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function lastCode(Customer $customer, VerificationPurpose $purpose): string
    {
        $code = null;
        Notification::assertSentTo($customer, OtpCodeNotification::class, function ($n) use (&$code, $purpose) {
            if ($n->purpose === $purpose) {
                $code = $n->code;
            }

            return true;
        });

        return $code;
    }

    public function test_register_creates_unverified_customer_returns_token_and_emails_a_code(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Dara', 'email' => 'Dara@Example.com',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('customer.email', 'dara@example.com')
            ->assertJsonPath('customer.email_verified', false)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonStructure(['token', 'expires_at']);

        $customer = Customer::firstWhere('email', 'dara@example.com');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $this->lastCode($customer, VerificationPurpose::EmailVerify));
        $this->assertDatabaseMissing('verification_tokens', ['code_hash' => $this->lastCode($customer, VerificationPurpose::EmailVerify)]);
    }

    public function test_register_validates_password_rules_and_unique_email(): void
    {
        Customer::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'X', 'email' => 'taken@example.com', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);

        $this->postJson('/api/v1/auth/register', [
            'name' => 'X', 'email' => 'new@example.com', 'password' => 'onlyletters', 'password_confirmation' => 'onlyletters',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);
    }

    public function test_customer_verifies_email_with_the_code(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Dara', 'email' => 'dara@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123',
        ]);
        $customer = Customer::firstWhere('email', 'dara@example.com');
        $token = $customer->createToken('t', ['customer'])->plainTextToken;
        $code = $this->lastCode($customer, VerificationPurpose::EmailVerify);
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->withToken($token)->postJson('/api/v1/auth/verify-email', ['code' => $wrong])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/auth/verify-email', ['code' => $code])
            ->assertOk()->assertJsonPath('customer.email_verified', true);

        // A used code cannot be replayed.
        $customer->refresh()->forceFill(['email_verified_at' => null])->save();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/auth/verify-email', ['code' => $code])->assertUnprocessable();
    }

    public function test_resending_a_code_cancels_the_previous_one(): void
    {
        $customer = Customer::factory()->unverified()->create();
        $token = $customer->createToken('t', ['customer'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/resend-verification')->assertOk();
        $first = $this->lastCode($customer, VerificationPurpose::EmailVerify);
        Notification::fake();
        $this->withToken($token)->postJson('/api/v1/auth/resend-verification')->assertOk();
        $second = $this->lastCode($customer, VerificationPurpose::EmailVerify);

        if ($first !== $second) {
            $this->withToken($token)->postJson('/api/v1/auth/verify-email', ['code' => $first])->assertUnprocessable();
        }
        $this->withToken($token)->postJson('/api/v1/auth/verify-email', ['code' => $second])->assertOk();
    }

    public function test_expired_code_is_rejected(): void
    {
        $customer = Customer::factory()->unverified()->create();
        $token = $customer->createToken('t', ['customer'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/auth/resend-verification');
        $code = $this->lastCode($customer, VerificationPurpose::EmailVerify);

        $this->travel(16)->minutes();

        $this->withToken($token)->postJson('/api/v1/auth/verify-email', ['code' => $code])->assertUnprocessable();
    }

    public function test_login_and_logout(): void
    {
        Customer::factory()->create(['email' => 'dara@example.com', 'password_hash' => 'secret123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'dara@example.com', 'password' => 'wrong-pass1'])
            ->assertUnauthorized();

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'DARA@example.com', 'password' => 'secret123'])
            ->assertOk()->json('token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_social_only_account_cannot_log_in_with_a_password(): void
    {
        Customer::factory()->create(['email' => 'g@example.com', 'password_hash' => null, 'google_id' => '123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'g@example.com', 'password' => 'anything1'])->assertUnauthorized();
    }

    public function test_forgot_and_reset_password(): void
    {
        $customer = Customer::factory()->create(['email' => 'dara@example.com', 'password_hash' => 'oldpass123']);
        $customer->createToken('old', ['customer']);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'dara@example.com'])->assertOk();
        $code = $this->lastCode($customer, VerificationPurpose::PasswordReset);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => 'dara@example.com', 'code' => $code, 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertOk();

        $this->assertTrue(Hash::check('newpass123', $customer->fresh()->password_hash));
        $this->assertSame(0, $customer->tokens()->count(), 'old sessions are revoked');
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'wrong-pass1'])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'x@example.com', 'password' => 'wrong-pass1'])->assertTooManyRequests();
    }

    public function test_rotating_emails_from_one_ip_is_still_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => "user{$i}@example.com", 'password' => 'wrong-pass1'])->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', ['email' => 'another@example.com', 'password' => 'wrong-pass1'])->assertTooManyRequests();
    }

    public function test_tokens_expire_after_seven_days(): void
    {
        Customer::factory()->create(['email' => 'dara@example.com', 'password_hash' => 'secret123']);
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'dara@example.com', 'password' => 'secret123'])->json('token');

        $this->travel(7)->days();
        $this->travel(1)->minutes();

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }
}

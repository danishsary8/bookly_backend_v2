<?php

namespace Tests\Feature\Auth;

use App\Enums\VerificationPurpose;
use App\Models\Customer;
use App\Notifications\AccountSecurityNotice;
use App\Notifications\OtpCodeNotification;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class ChangeEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function token(Customer $customer): string
    {
        return $customer->createToken('t', ['customer'])->plainTextToken;
    }

    /** The code emailed to an address (the last one sent there). */
    private function codeSentTo(string $email): string
    {
        $code = null;
        Notification::assertSentOnDemand(OtpCodeNotification::class, function ($notification, $channels, $notifiable) use ($email, &$code) {
            if (($notifiable->routes['mail'] ?? null) !== $email) {
                return false;
            }
            $code = $notification->code;

            return $notification->purpose === VerificationPurpose::EmailChange;
        });

        return $code;
    }

    public function test_a_customer_changes_their_email_with_their_password_and_a_code(): void
    {
        $customer = Customer::factory()->create(['email' => 'old@example.com', 'password_hash' => 'reading123']);
        $token = $this->token($customer);
        $other = $this->token($customer);

        $this->withToken($token)->postJson('/api/v1/me/email', ['email' => 'New@Example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->withToken($token)->postJson('/api/v1/me/email', ['email' => 'new@example.com', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password' => 'That password is incorrect.']);
        Notification::assertNothingSent();

        $this->withToken($token)->postJson('/api/v1/me/email', ['email' => 'New@Example.com', 'password' => 'reading123'])
            ->assertOk()->assertJsonPath('email', 'new@example.com');
        // Nothing changes until the code comes back.
        $this->assertSame('old@example.com', $customer->fresh()->email);

        $this->withToken($token)->postJson('/api/v1/me/email/verify', ['code' => '000000'])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/me/email/verify', ['code' => $this->codeSentTo('new@example.com')])
            ->assertOk()
            ->assertJsonPath('message', 'Email changed. Other devices were signed out.')
            ->assertJsonPath('customer.email', 'new@example.com')
            ->assertJsonPath('customer.email_verified', true);

        $this->assertSame(1, $customer->tokens()->count());
        $this->assertNull(PersonalAccessToken::findToken($other));
        Notification::assertSentOnDemand(AccountSecurityNotice::class, fn ($n, $c, $notifiable) => $notifiable->routes['mail'] === 'old@example.com'
            && str_contains($n->what, 'n***@example.com'));
        $this->postJson('/api/v1/auth/login', ['email' => 'new@example.com', 'password' => 'reading123'])->assertOk();
    }

    public function test_a_phone_only_facebook_customer_adds_an_email_without_a_password(): void
    {
        $customer = Customer::factory()->create(['email' => null, 'email_verified_at' => null, 'password_hash' => null, 'facebook_id' => 'fb-1', 'phone_e164' => '+85512345678', 'phone_verified_at' => now()]);
        $token = $this->token($customer);

        $this->withToken($token)->postJson('/api/v1/me/email', ['email' => 'dara@example.com'])->assertOk();
        $this->withToken($token)->postJson('/api/v1/me/email/verify', ['code' => $this->codeSentTo('dara@example.com')])
            ->assertOk()->assertJsonPath('message', 'Email added. Other devices were signed out.');

        $this->assertSame('dara@example.com', $customer->fresh()->email);
        Notification::assertNotSentTo($customer, AccountSecurityNotice::class); // no old address to tell
    }

    public function test_an_email_used_by_another_account_is_refused(): void
    {
        Customer::factory()->create(['email' => 'taken@example.com']);
        Customer::factory()->create(['email' => 'closed@example.com'])->delete();
        $customer = Customer::factory()->create(['password_hash' => 'reading123']);
        $token = $this->token($customer);

        foreach (['taken@example.com', 'closed@example.com'] as $email) {
            $this->withToken($token)->postJson('/api/v1/me/email', ['email' => $email, 'password' => 'reading123'])
                ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'already used by another Bookly account']);
        }
    }

    public function test_someone_taking_the_address_before_the_code_comes_back_wins(): void
    {
        $customer = Customer::factory()->create(['password_hash' => 'reading123']);
        $token = $this->token($customer);
        $this->withToken($token)->postJson('/api/v1/me/email', ['email' => 'race@example.com', 'password' => 'reading123'])->assertOk();
        Customer::factory()->create(['email' => 'race@example.com']);

        $this->withToken($token)->postJson('/api/v1/me/email/verify', ['code' => $this->codeSentTo('race@example.com')])
            ->assertUnprocessable()->assertJsonPath('message', 'This email is already used by another Bookly account.');
    }

    public function test_the_same_email_is_not_a_change(): void
    {
        $customer = Customer::factory()->create(['email' => 'same@example.com', 'password_hash' => 'reading123']);

        $this->withToken($this->token($customer))->postJson('/api/v1/me/email', ['email' => 'SAME@example.com', 'password' => 'reading123'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'This is already your email.']);
    }

    public function test_an_email_change_code_cannot_verify_the_old_email(): void
    {
        // The old address was never proven; a code sent to the new address must not prove it.
        $customer = Customer::factory()->unverified()->create(['email' => 'unproven@example.com', 'password_hash' => 'reading123']);
        $token = $this->token($customer);
        $this->withToken($token)->postJson('/api/v1/me/email', ['email' => 'mine@example.com', 'password' => 'reading123'])->assertOk();

        $this->withToken($token)->postJson('/api/v1/auth/verify-email', ['code' => $this->codeSentTo('mine@example.com')])->assertUnprocessable();
        $this->assertNull($customer->fresh()->email_verified_at);
    }

    public function test_codes_to_the_old_address_stop_working(): void
    {
        $customer = Customer::factory()->create(['email' => 'old@example.com', 'password_hash' => 'reading123']);
        app(OtpService::class)->issue($customer, VerificationPurpose::PasswordReset);
        $token = $this->token($customer);
        $this->withToken($token)->postJson('/api/v1/me/email', ['email' => 'new@example.com', 'password' => 'reading123'])->assertOk();
        $this->withToken($token)->postJson('/api/v1/me/email/verify', ['code' => $this->codeSentTo('new@example.com')])->assertOk();

        $this->assertSame(0, $customer->fresh()->getConnection()->table('verification_tokens')
            ->where('user_id', $customer->id)->where('purpose', 'password_reset')->whereNull('used_at')->count());
    }

    public function test_an_account_can_ask_for_five_codes_an_hour(): void
    {
        $customer = Customer::factory()->create(['password_hash' => 'reading123']);
        $token = $this->token($customer);

        for ($i = 1; $i <= 5; $i++) {
            $this->withToken($token)->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->postJson('/api/v1/me/email', ['email' => "try{$i}@example.com", 'password' => 'reading123'])->assertOk();
        }
        $this->withToken($token)->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->postJson('/api/v1/me/email', ['email' => 'try6@example.com', 'password' => 'reading123'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'You have asked for 5 codes in the last hour']);
    }

    public function test_only_the_latest_address_can_be_confirmed(): void
    {
        $customer = Customer::factory()->create(['password_hash' => 'reading123']);
        $token = $this->token($customer);
        $this->withToken($token)->postJson('/api/v1/me/email', ['email' => 'first@example.com', 'password' => 'reading123'])->assertOk();
        $first = $this->codeSentTo('first@example.com');
        $this->withToken($token)->postJson('/api/v1/me/email', ['email' => 'second@example.com', 'password' => 'reading123'])->assertOk();

        $this->withToken($token)->postJson('/api/v1/me/email/verify', ['code' => $first])->assertUnprocessable();
        $this->withToken($token)->postJson('/api/v1/me/email/verify', ['code' => $this->codeSentTo('second@example.com')])
            ->assertOk()->assertJsonPath('customer.email', 'second@example.com');
    }
}

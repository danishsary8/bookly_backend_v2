<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Notifications\OtpCodeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Security review 2026-10-15: an account without a password can only add a new way in (a password, an email,
 * Google/Facebook) right after signing in; an older (possibly stolen) session is asked to sign in again.
 */
class ConfirmIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    /** A Facebook-only account, signed in 11 minutes ago. */
    private function staleSession(): string
    {
        $customer = Customer::factory()->create(['password_hash' => null, 'facebook_id' => 'fb-1', 'email' => 'dara@example.com']);
        $token = $customer->createToken('t', ['customer'])->plainTextToken;
        $this->travel(11)->minutes();

        return $token;
    }

    public function test_a_first_password_needs_a_recent_sign_in(): void
    {
        $token = $this->staleSession();
        $body = ['password' => 'newpass123', 'password_confirmation' => 'newpass123'];

        $this->withToken($token)->putJson('/api/v1/me/password', $body)->assertForbidden()->assertJsonPath('reason', 'sign_in_again');
        $this->assertNull(Customer::first()->password_hash);

        $this->app['auth']->forgetGuards(); // a new request with another token
        $fresh = Customer::first()->createToken('t', ['customer'])->plainTextToken;
        $this->withToken($fresh)->putJson('/api/v1/me/password', $body)->assertOk();
    }

    public function test_a_new_email_needs_a_recent_sign_in(): void
    {
        $this->withToken($this->staleSession())->postJson('/api/v1/me/email', ['email' => 'thief@example.com'])
            ->assertForbidden()->assertJsonPath('reason', 'sign_in_again');
        Notification::assertSentOnDemandTimes(OtpCodeNotification::class, 0);
    }

    public function test_connecting_google_needs_a_recent_sign_in(): void
    {
        $this->withToken($this->staleSession())->postJson('/api/v1/me/connections/google', ['access_token' => 'thief-token'])
            ->assertForbidden()->assertJsonPath('reason', 'sign_in_again');
        $this->assertNull(Customer::first()->google_id);
    }
}

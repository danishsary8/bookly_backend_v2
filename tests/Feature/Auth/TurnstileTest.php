<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Rules\Turnstile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TurnstileTest extends TestCase
{
    use RefreshDatabase;

    private const SITEVERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['services.turnstile.secret' => 'test-secret']);
    }

    private function cloudflareSays(bool $success): void
    {
        Http::fake([self::SITEVERIFY => Http::response(['success' => $success, 'error-codes' => $success ? [] : ['invalid-input-response']])]);
    }

    public function test_sign_up_needs_a_token_cloudflare_accepts(): void
    {
        $this->cloudflareSays(true);
        $body = ['name' => 'Dara', 'email' => 'dara@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123'];

        $this->postJson('/api/v1/auth/register', $body)->assertUnprocessable()->assertJsonPath('errors.turnstile_token.0', Turnstile::FAILED);
        Http::assertNothingSent();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('/api/v1/auth/register', [...$body, 'turnstile_token' => 'good-token'])->assertCreated();
        Http::assertSent(fn (Request $r) => $r->url() === self::SITEVERIFY
            && $r['secret'] === 'test-secret' && $r['response'] === 'good-token' && $r['remoteip'] === '203.0.113.9');
    }

    public function test_a_rejected_token_stops_sign_in_forgot_password_and_resend(): void
    {
        $this->cloudflareSays(false);
        $customer = Customer::factory()->unverified()->create(['email' => 'dara@example.com', 'password_hash' => 'secret123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'dara@example.com', 'password' => 'secret123', 'turnstile_token' => 'bot'])
            ->assertUnprocessable()->assertJsonPath('errors.turnstile_token.0', Turnstile::FAILED);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'dara@example.com', 'turnstile_token' => 'bot'])
            ->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');
        $this->withToken($customer->createToken('t', ['customer'])->plainTextToken)
            ->postJson('/api/v1/auth/resend-verification', ['turnstile_token' => 'bot'])->assertUnprocessable()->assertJsonValidationErrors('turnstile_token');

        Notification::assertNothingSent();
    }

    public function test_sign_in_works_with_an_accepted_token(): void
    {
        $this->cloudflareSays(true);
        Customer::factory()->create(['email' => 'dara@example.com', 'password_hash' => 'secret123']);

        $this->postJson('/api/v1/auth/login', ['email' => 'dara@example.com', 'password' => 'secret123', 'turnstile_token' => 'ok'])->assertOk();
    }

    public function test_cloudflare_being_down_fails_closed_with_its_own_message(): void
    {
        Http::fake([self::SITEVERIFY => Http::response('Bad gateway', 502)]);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'dara@example.com', 'turnstile_token' => 'any'])
            ->assertUnprocessable()->assertJsonPath('errors.turnstile_token.0', Turnstile::UNREACHABLE);
    }

    public function test_it_is_off_without_a_secret(): void
    {
        config(['services.turnstile.secret' => null]);
        Http::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'dara@example.com'])->assertOk();
        Http::assertNothingSent();
    }
}

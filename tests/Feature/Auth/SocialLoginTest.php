<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'bookly-google', 'services.facebook.client_id' => '4242', 'services.facebook.client_secret' => 'fb-secret']);
    }

    /** What Google / Facebook answer when asked which app the token belongs to. */
    private function tokenIssuedTo(string $googleClient = 'bookly-google', string $facebookApp = '4242'): void
    {
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => Http::response(['aud' => $googleClient, 'azp' => $googleClient, 'expires_in' => 3000]),
            'graph.facebook.com/debug_token*' => Http::response(['data' => ['app_id' => $facebookApp, 'is_valid' => true]]),
        ]);
    }

    private function fakeProvider(string $provider, ?SocialiteUser $user, bool $fails = false): void
    {
        $this->tokenIssuedTo();
        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $fails
            ? $driver->shouldReceive('userFromToken')->andThrow(new RuntimeException('bad token'))
            : $driver->shouldReceive('userFromToken')->with('provider-token')->andReturn($user);

        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }

    private function profile(string $id, ?string $email, string $name = 'Sok Dara'): SocialiteUser
    {
        return (new SocialiteUser)->setRaw(['email_verified' => true])->map(['id' => $id, 'email' => $email, 'name' => $name]);
    }

    public function test_new_google_user_gets_a_verified_account(): void
    {
        $this->fakeProvider('google', $this->profile('g-123', 'Sok@Gmail.com'));

        $this->postJson('/api/v1/auth/social/google', ['access_token' => 'provider-token'])
            ->assertCreated()
            ->assertJsonPath('customer.email', 'sok@gmail.com')
            ->assertJsonPath('customer.email_verified', true)
            ->assertJsonPath('customer.has_password', false)
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('customers', ['email' => 'sok@gmail.com', 'google_id' => 'g-123']);
    }

    public function test_existing_email_account_is_linked_not_duplicated(): void
    {
        $existing = Customer::factory()->unverified()->create(['email' => 'sok@gmail.com']);
        $this->fakeProvider('facebook', $this->profile('fb-9', 'sok@gmail.com'));

        $this->postJson('/api/v1/auth/social/facebook', ['access_token' => 'provider-token'])->assertOk();

        $this->assertSame(1, Customer::count());
        $this->assertSame('fb-9', $existing->fresh()->facebook_id);
        $this->assertNotNull($existing->fresh()->email_verified_at);
    }

    public function test_linking_an_unverified_account_removes_the_unproven_password_and_sessions(): void
    {
        // Someone registered the victim's email (never verified) and kept the password.
        $squatter = Customer::factory()->unverified()->create(['email' => 'sok@gmail.com', 'password_hash' => 'squatter123']);
        $squatter->createToken('t', ['customer']);
        $this->fakeProvider('google', $this->profile('g-1', 'sok@gmail.com'));

        $this->postJson('/api/v1/auth/social/google', ['access_token' => 'provider-token'])->assertOk();

        $this->assertNull($squatter->fresh()->password_hash);
        $this->assertSame(1, $squatter->tokens()->count(), 'only the new social login token');
        $this->postJson('/api/v1/auth/login', ['email' => 'sok@gmail.com', 'password' => 'squatter123'])->assertUnauthorized();
    }

    public function test_linking_a_verified_account_keeps_its_password(): void
    {
        $owner = Customer::factory()->create(['email' => 'sok@gmail.com', 'password_hash' => 'mypass123']);
        $this->fakeProvider('google', $this->profile('g-1', 'sok@gmail.com'));

        $this->postJson('/api/v1/auth/social/google', ['access_token' => 'provider-token'])->assertOk();

        $this->postJson('/api/v1/auth/login', ['email' => 'sok@gmail.com', 'password' => 'mypass123'])->assertOk();
    }

    public function test_tokens_issued_to_another_app_are_rejected(): void
    {
        $this->tokenIssuedTo(googleClient: 'some-other-app', facebookApp: '9999'); // first matching fake wins
        $this->fakeProvider('google', $this->profile('g-1', 'sok@gmail.com'));

        $this->postJson('/api/v1/auth/social/google', ['access_token' => 'provider-token'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/social/facebook', ['access_token' => 'provider-token'])->assertUnauthorized();
        $this->assertSame(0, Customer::count());
    }

    public function test_social_login_is_off_until_the_client_id_is_configured(): void
    {
        config(['services.google.client_id' => null]);
        $this->fakeProvider('google', $this->profile('g-1', 'sok@gmail.com'));

        $this->postJson('/api/v1/auth/social/google', ['access_token' => 'provider-token'])->assertUnauthorized();
    }

    public function test_google_address_that_google_has_not_verified_is_rejected(): void
    {
        $this->fakeProvider('google', (new SocialiteUser)->setRaw(['email_verified' => false])->map(['id' => 'g-1', 'email' => 'sok@gmail.com', 'name' => 'Sok']));

        $this->postJson('/api/v1/auth/social/google', ['access_token' => 'provider-token'])->assertUnprocessable();
        $this->assertSame(0, Customer::count());
    }

    public function test_invalid_provider_token_is_rejected(): void
    {
        $this->fakeProvider('google', null, fails: true);

        $this->postJson('/api/v1/auth/social/google', ['access_token' => 'provider-token'])->assertUnauthorized();
    }

    public function test_provider_account_without_email_is_rejected(): void
    {
        $this->fakeProvider('facebook', $this->profile('fb-1', null));

        $this->postJson('/api/v1/auth/social/facebook', ['access_token' => 'provider-token'])->assertUnprocessable();
        $this->assertSame(0, Customer::count());
    }

    public function test_unknown_provider_is_404(): void
    {
        $this->postJson('/api/v1/auth/social/twitter', ['access_token' => 'x'])->assertNotFound();
    }
}

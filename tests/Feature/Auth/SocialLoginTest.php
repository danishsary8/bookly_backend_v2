<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    private function fakeProvider(string $provider, ?SocialiteUser $user, bool $fails = false): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $fails
            ? $driver->shouldReceive('userFromToken')->andThrow(new RuntimeException('bad token'))
            : $driver->shouldReceive('userFromToken')->with('provider-token')->andReturn($user);

        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }

    private function profile(string $id, ?string $email, string $name = 'Sok Dara'): SocialiteUser
    {
        return (new SocialiteUser)->map(['id' => $id, 'email' => $email, 'name' => $name]);
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

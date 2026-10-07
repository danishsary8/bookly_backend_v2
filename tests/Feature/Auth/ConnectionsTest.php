<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Notifications\AccountSecurityNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class ConnectionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.client_id' => 'bookly-google', 'services.facebook.client_id' => '4242', 'services.facebook.client_secret' => 's']);
        Http::fake([
            'oauth2.googleapis.com/tokeninfo*' => fn ($request) => str_contains($request->url(), 'other-app')
                ? Http::response(['aud' => 'another-app'])
                : Http::response(['aud' => 'bookly-google']),
            'graph.facebook.com/debug_token*' => Http::response(['data' => ['is_valid' => true, 'app_id' => '4242']]),
        ]);
        Notification::fake();
    }

    private function providerReturns(string $provider, string $id): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('userFromToken')->andReturn((new SocialiteUser)->map(['id' => $id, 'email' => 'someone@example.com']));
        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }

    private function as(Customer $customer): static
    {
        return $this->withToken($customer->createToken('t', ['customer'])->plainTextToken);
    }

    public function test_a_customer_connects_google_after_typing_their_password(): void
    {
        $this->providerReturns('google', 'g-1');
        $customer = Customer::factory()->create(['password_hash' => 'reading123', 'google_id' => null]);

        $this->as($customer)->postJson('/api/v1/me/connections/google', ['access_token' => 't'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->as($customer)->postJson('/api/v1/me/connections/google', ['access_token' => 't', 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password' => 'That password is incorrect.']);
        $this->as($customer)->postJson('/api/v1/me/connections/google', ['access_token' => 't', 'password' => 'reading123'])
            ->assertOk()
            ->assertJsonPath('customer.connected.google', true)
            ->assertJsonPath('customer.sign_in_methods', ['password', 'google']);

        $this->assertSame('g-1', $customer->fresh()->google_id);
        Notification::assertSentTo($customer, AccountSecurityNotice::class, fn ($n) => str_contains($n->subject, 'Google was connected'));
    }

    public function test_accounts_without_a_password_connect_with_the_token_alone(): void
    {
        $this->providerReturns('google', 'g-1');
        $customer = Customer::factory()->create(['password_hash' => null, 'email' => null, 'email_verified_at' => null, 'facebook_id' => 'fb-1']);

        $this->as($customer)->postJson('/api/v1/me/connections/google', ['access_token' => 't'])->assertOk();
        $this->assertSame('g-1', $customer->fresh()->google_id);
        Notification::assertNothingSent(); // no proven email to tell
    }

    public function test_a_provider_account_linked_elsewhere_is_refused(): void
    {
        $this->providerReturns('facebook', 'fb-9');
        Customer::factory()->create(['facebook_id' => 'fb-9']);
        $customer = Customer::factory()->create(['password_hash' => 'reading123']);

        $this->as($customer)->postJson('/api/v1/me/connections/facebook', ['access_token' => 't', 'password' => 'reading123'])
            ->assertConflict()->assertJsonPath('message', 'This Facebook account is already used by another Bookly account.');
        $this->assertNull($customer->fresh()->facebook_id);
    }

    public function test_a_closed_account_keeps_its_provider_reserved(): void
    {
        $this->providerReturns('google', 'g-closed');
        Customer::factory()->create(['google_id' => 'g-closed'])->delete();
        $customer = Customer::factory()->create(['password_hash' => 'reading123']);

        $this->as($customer)->postJson('/api/v1/me/connections/google', ['access_token' => 't', 'password' => 'reading123'])->assertConflict();
    }

    public function test_a_second_google_account_needs_the_first_disconnected(): void
    {
        $this->providerReturns('google', 'g-new');
        $customer = Customer::factory()->create(['password_hash' => 'reading123', 'google_id' => 'g-old']);

        $this->as($customer)->postJson('/api/v1/me/connections/google', ['access_token' => 't', 'password' => 'reading123'])
            ->assertConflict()->assertJsonPath('message', 'Another Google account is connected. Disconnect it first.');
        $this->assertSame('g-old', $customer->fresh()->google_id);
    }

    public function test_tokens_issued_to_another_app_are_refused(): void
    {
        $customer = Customer::factory()->create(['password_hash' => 'reading123']);

        $this->as($customer)->postJson('/api/v1/me/connections/google', ['access_token' => 'other-app', 'password' => 'reading123'])
            ->assertUnprocessable()->assertJsonPath('message', 'Could not verify your Google account. Try again.');
        $this->assertNull($customer->fresh()->google_id);
    }

    public function test_disconnecting_keeps_another_way_in(): void
    {
        $customer = Customer::factory()->create(['password_hash' => 'reading123', 'google_id' => 'g-1']);

        $this->as($customer)->deleteJson('/api/v1/me/connections/google')
            ->assertOk()->assertJsonPath('customer.connected.google', false)->assertJsonPath('customer.sign_in_methods', ['password']);
        $this->assertNull($customer->fresh()->google_id);
        Notification::assertSentTo($customer, AccountSecurityNotice::class, fn ($n) => str_contains($n->subject, 'Google was disconnected'));
    }

    public function test_the_last_way_in_cannot_be_removed(): void
    {
        $customer = Customer::factory()->create(['password_hash' => null, 'google_id' => 'g-1', 'facebook_id' => null]);

        $this->as($customer)->deleteJson('/api/v1/me/connections/google')
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Add a password first, so you can still sign in.')
            ->assertJsonPath('reason', 'last_way_in');
        $this->assertSame('g-1', $customer->fresh()->google_id);
    }

    public function test_a_password_without_an_email_is_not_a_way_in(): void
    {
        // A phone-only Facebook customer who set a password still has no email to type it with.
        $customer = Customer::factory()->create(['email' => null, 'email_verified_at' => null, 'password_hash' => 'reading123', 'facebook_id' => 'fb-1']);

        $this->as($customer)->deleteJson('/api/v1/me/connections/facebook')->assertUnprocessable()->assertJsonPath('reason', 'last_way_in');
    }

    public function test_with_two_providers_either_can_go(): void
    {
        $customer = Customer::factory()->create(['password_hash' => null, 'google_id' => 'g-1', 'facebook_id' => 'fb-1']);

        $this->as($customer)->deleteJson('/api/v1/me/connections/facebook')->assertOk();
        $this->as($customer)->deleteJson('/api/v1/me/connections/google')->assertUnprocessable();
    }

    public function test_disconnecting_something_not_connected_is_harmless(): void
    {
        $customer = Customer::factory()->create(['password_hash' => 'reading123', 'facebook_id' => null]);

        $this->as($customer)->deleteJson('/api/v1/me/connections/facebook')->assertOk()->assertJsonPath('message', 'Facebook is not connected.');
        Notification::assertNothingSent();
    }

    public function test_unknown_providers_and_guests_are_refused(): void
    {
        $customer = Customer::factory()->create();

        $this->as($customer)->postJson('/api/v1/me/connections/twitter', ['access_token' => 't'])->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->deleteJson('/api/v1/me/connections/google')->assertUnauthorized();
    }
}

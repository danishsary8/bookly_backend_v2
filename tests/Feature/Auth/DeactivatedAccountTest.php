<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\StaffUser;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class DeactivatedAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivated_customer_cannot_log_in_but_wrong_password_still_says_invalid(): void
    {
        Customer::factory()->create(['email' => 'off@example.com', 'password_hash' => 'secret123', 'is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['email' => 'off@example.com', 'password' => 'wrong-pass1'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'off@example.com', 'password' => 'secret123'])
            ->assertForbidden()->assertJsonPath('message', 'This account has been deactivated. Please contact support.');
    }

    public function test_deactivated_customer_cannot_use_social_login(): void
    {
        Customer::factory()->create(['email' => 'off@example.com', 'google_id' => 'g-1', 'is_active' => false]);
        $driver = Mockery::mock();
        $driver->shouldReceive('stateless')->andReturnSelf();
        $driver->shouldReceive('userFromToken')->andReturn((new SocialiteUser)->map(['id' => 'g-1', 'email' => 'off@example.com', 'name' => 'Off']));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->postJson('/api/v1/auth/social/google', ['access_token' => 't'])->assertForbidden();
    }

    public function test_deactivated_staff_cannot_log_in_or_finish_a_pending_2fa_login(): void
    {
        $staff = StaffUser::factory()->withTwoFactor('JBSWY3DPEHPK3PXP')->create(['email' => 's@shop.test', 'password_hash' => 'secret123']);
        $challenge = $this->postJson('/api/v1/staff/auth/login', ['email' => 's@shop.test', 'password' => 'secret123'])->json('challenge_token');

        $staff->update(['is_active' => false]);

        $code = app(TotpService::class)->codeAt('JBSWY3DPEHPK3PXP', now()->getTimestamp());
        $this->postJson('/api/v1/staff/auth/two-factor/challenge', ['challenge_token' => $challenge, 'code' => $code])->assertUnauthorized();
        $this->postJson('/api/v1/staff/auth/login', ['email' => 's@shop.test', 'password' => 'secret123'])->assertForbidden();
    }
}

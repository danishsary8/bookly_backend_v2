<?php

namespace Tests\Feature\Auth;

use App\Enums\VerificationPurpose;
use App\Models\Customer;
use App\Models\StaffUser;
use App\Notifications\OtpCodeNotification;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class StaffAuthTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();

        // Stand-ins for staff and admin feature routes, using the real middleware stacks.
        Route::middleware(['api', 'auth:sanctum', 'abilities:staff', 'staff.2fa'])
            ->get('/api/v1/__test/staff', fn () => response()->json(['ok' => true]));
        Route::middleware(['api', 'auth:sanctum', 'abilities:staff,admin', 'staff.2fa'])
            ->get('/api/v1/__test/admin', fn () => response()->json(['ok' => true]));
    }

    private function code(string $secret = self::SECRET): string
    {
        return app(TotpService::class)->codeAt($secret, now()->getTimestamp());
    }

    public function test_staff_without_2fa_gets_a_token_that_only_works_for_2fa_setup(): void
    {
        StaffUser::factory()->create(['email' => 'staff@shop.test', 'password_hash' => 'secret123']);

        $response = $this->postJson('/api/v1/staff/auth/login', ['email' => 'staff@shop.test', 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('two_factor_setup_required', true);
        $token = $response->json('token');

        $this->withToken($token)->getJson('/api/v1/__test/staff')
            ->assertForbidden()->assertJsonPath('two_factor_setup_required', true);
        $this->withToken($token)->getJson('/api/v1/staff/auth/me')->assertOk();
    }

    public function test_full_2fa_setup_then_challenge_login(): void
    {
        $staff = StaffUser::factory()->create(['email' => 'staff@shop.test', 'password_hash' => 'secret123']);
        $setupToken = $staff->createToken('t', $staff->tokenAbilities())->plainTextToken;

        $secret = $this->withToken($setupToken)->postJson('/api/v1/staff/auth/two-factor/setup')
            ->assertOk()->assertJsonStructure(['secret', 'otpauth_uri'])->json('secret');
        $this->assertStringStartsWith('otpauth://totp/', $this->withToken($setupToken)->postJson('/api/v1/staff/auth/two-factor/setup')->json('otpauth_uri'));
        $secret = $staff->fresh()->two_factor_secret; // second setup call replaced the secret

        $this->withToken($setupToken)->postJson('/api/v1/staff/auth/two-factor/confirm', ['code' => '000000'])->assertUnprocessable();
        $this->withToken($setupToken)->postJson('/api/v1/staff/auth/two-factor/confirm', ['code' => $this->code($secret)])->assertOk();
        $this->assertTrue($staff->fresh()->two_factor_enabled);

        // Now login needs the app code.
        $challenge = $this->postJson('/api/v1/staff/auth/login', ['email' => 'staff@shop.test', 'password' => 'secret123'])
            ->assertOk()->assertJsonPath('two_factor_required', true)->assertJsonMissingPath('token')->json('challenge_token');

        $this->travel(31)->seconds(); // next TOTP window so the confirm code is not counted as a replay
        $token = $this->postJson('/api/v1/staff/auth/two-factor/challenge', ['challenge_token' => $challenge, 'code' => $this->code($secret)])
            ->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/__test/staff')->assertOk();
    }

    public function test_challenge_rejects_wrong_code_reused_code_and_expired_challenge(): void
    {
        StaffUser::factory()->withTwoFactor(self::SECRET)->create(['email' => 's@shop.test', 'password_hash' => 'secret123']);
        $login = fn () => $this->postJson('/api/v1/staff/auth/login', ['email' => 's@shop.test', 'password' => 'secret123'])->json('challenge_token');

        $challenge = $login();
        $this->postJson('/api/v1/staff/auth/two-factor/challenge', ['challenge_token' => $challenge, 'code' => '000000'])->assertUnprocessable();

        $code = $this->code();
        $this->postJson('/api/v1/staff/auth/two-factor/challenge', ['challenge_token' => $challenge, 'code' => $code])->assertOk();
        // Challenge is single use, and the same app code cannot be replayed with a new challenge.
        $this->postJson('/api/v1/staff/auth/two-factor/challenge', ['challenge_token' => $challenge, 'code' => $code])->assertUnauthorized();
        $this->postJson('/api/v1/staff/auth/two-factor/challenge', ['challenge_token' => $login(), 'code' => $code])->assertUnprocessable();

        $old = $login();
        $this->travel(6)->minutes();
        $this->postJson('/api/v1/staff/auth/two-factor/challenge', ['challenge_token' => $old, 'code' => $this->code()])->assertUnauthorized();
    }

    public function test_admin_only_routes_reject_regular_staff(): void
    {
        $staff = StaffUser::factory()->withTwoFactor()->create();
        $admin = StaffUser::factory()->admin()->withTwoFactor()->create();

        $this->withToken($staff->createToken('t', $staff->tokenAbilities())->plainTextToken)
            ->getJson('/api/v1/__test/admin')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($admin->createToken('t', $admin->tokenAbilities())->plainTextToken)
            ->getJson('/api/v1/__test/admin')->assertOk();
    }

    public function test_customer_token_cannot_reach_staff_routes(): void
    {
        $token = Customer::factory()->create()->createToken('t', ['customer'])->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/staff/auth/me')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/__test/staff')->assertForbidden();
    }

    public function test_wrong_password_is_401(): void
    {
        StaffUser::factory()->create(['email' => 's@shop.test', 'password_hash' => 'secret123']);

        $this->postJson('/api/v1/staff/auth/login', ['email' => 's@shop.test', 'password' => 'nope12345'])->assertUnauthorized();
    }

    public function test_staff_password_reset_keeps_2fa_enabled(): void
    {
        Notification::fake();
        $staff = StaffUser::factory()->withTwoFactor()->create(['email' => 's@shop.test']);

        $this->postJson('/api/v1/staff/auth/forgot-password', ['email' => 's@shop.test'])->assertOk();
        $code = null;
        Notification::assertSentTo($staff, OtpCodeNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return $n->purpose === VerificationPurpose::PasswordReset;
        });

        $this->postJson('/api/v1/staff/auth/reset-password', [
            'email' => 's@shop.test', 'code' => $code, 'password' => 'newpass123', 'password_confirmation' => 'newpass123',
        ])->assertOk();

        $this->assertTrue(Hash::check('newpass123', $staff->fresh()->password_hash));
        $this->assertTrue($staff->fresh()->two_factor_enabled);
    }
}

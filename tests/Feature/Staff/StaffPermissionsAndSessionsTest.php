<?php

namespace Tests\Feature\Staff;

use App\Models\Order;
use App\Models\StaffLoginHistory;
use App\Models\StaffUser;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class StaffPermissionsAndSessionsTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_login_records_login_history_for_success_and_failures(): void
    {
        $staff = StaffUser::factory()->withTwoFactor('JBSWY3DPEHPK3PXP')->create([
            'email' => 'member@bookly.test',
            'password_hash' => 'Password123!',
        ]);

        // 1. Failed password attempt
        $this->postJson('/api/v1/staff/auth/login', [
            'email' => 'member@bookly.test',
            'password' => 'WrongPassword',
        ])->assertUnauthorized();

        $this->assertDatabaseHas('staff_login_histories', [
            'staff_user_id' => $staff->id,
            'status' => 'failed_password',
        ]);

        // 2. Step 1 valid password returns 2FA challenge
        $loginRes = $this->postJson('/api/v1/staff/auth/login', [
            'email' => 'member@bookly.test',
            'password' => 'Password123!',
        ])->assertOk();

        $challengeToken = $loginRes->json('challenge_token');
        $this->assertNotNull($challengeToken);

        // 3. Failed 2FA challenge
        $this->postJson('/api/v1/staff/auth/two-factor/challenge', [
            'challenge_token' => $challengeToken,
            'code' => '000000',
        ])->assertUnprocessable();

        $this->assertDatabaseHas('staff_login_histories', [
            'staff_user_id' => $staff->id,
            'status' => 'failed_2fa',
        ]);

        // 4. Successful 2FA challenge
        $validCode = app(TotpService::class)->codeAt('JBSWY3DPEHPK3PXP', now()->timestamp);
        $this->postJson('/api/v1/staff/auth/two-factor/challenge', [
            'challenge_token' => $challengeToken,
            'code' => $validCode,
        ])->assertOk();

        $this->assertDatabaseHas('staff_login_histories', [
            'staff_user_id' => $staff->id,
            'status' => 'success',
        ]);
    }

    public function test_staff_can_view_sessions_and_revoke_others(): void
    {
        $staff = StaffUser::factory()->withTwoFactor()->create();
        $token1 = $staff->createToken('Desktop Chrome', ['staff'])->plainTextToken;
        $token2 = $staff->createToken('Mobile Safari', ['staff'])->plainTextToken;

        // Using token1: session list shows 2 sessions, with token1 is_current = true
        $res = $this->asToken($token1)->getJson('/api/v1/staff/sessions')->assertOk();
        $res->assertJsonCount(2, 'data');
        $sessions = $res->json('data');
        $currentSession = collect($sessions)->firstWhere('is_current', true);
        $otherSession = collect($sessions)->firstWhere('is_current', false);

        $this->assertNotNull($currentSession);
        $this->assertNotNull($otherSession);

        // Revoke others
        $this->asToken($token1)->postJson('/api/v1/staff/sessions/revoke-others')->assertOk();

        // Token1 still works
        $this->asToken($token1)->getJson('/api/v1/staff/sessions')->assertOk()->assertJsonCount(1, 'data');

        // Token2 is now revoked (401)
        $this->asToken($token2)->getJson('/api/v1/staff/sessions')->assertUnauthorized();
    }

    public function test_staff_can_revoke_specific_session(): void
    {
        $staff = StaffUser::factory()->withTwoFactor()->create();
        $token1 = $staff->createToken('Desktop 1', ['staff'])->plainTextToken;
        $token2Model = $staff->createToken('Desktop 2', ['staff']);
        $token2 = $token2Model->plainTextToken;

        $this->asToken($token1)->deleteJson("/api/v1/staff/sessions/{$token2Model->accessToken->id}")->assertOk();

        $this->asToken($token2)->getJson('/api/v1/staff/sessions')->assertUnauthorized();
        $this->asToken($token1)->getJson('/api/v1/staff/sessions')->assertOk();
    }

    public function test_staff_can_sign_out_everywhere(): void
    {
        $staff = StaffUser::factory()->withTwoFactor()->create();
        $token1 = $staff->createToken('Desktop 1', ['staff'])->plainTextToken;
        $token2 = $staff->createToken('Desktop 2', ['staff'])->plainTextToken;

        $this->asToken($token1)->postJson('/api/v1/staff/sessions/revoke-all')->assertOk();

        $this->asToken($token1)->getJson('/api/v1/staff/sessions')->assertUnauthorized();
        $this->asToken($token2)->getJson('/api/v1/staff/sessions')->assertUnauthorized();
    }

    public function test_staff_can_view_own_login_history_and_admin_can_view_member_login_history(): void
    {
        $admin = StaffUser::factory()->admin()->withTwoFactor()->create();
        $adminToken = $admin->createToken('test', $admin->tokenAbilities())->plainTextToken;

        $staff = StaffUser::factory()->withTwoFactor()->create();
        $staffToken = $staff->createToken('test', $staff->tokenAbilities())->plainTextToken;

        StaffLoginHistory::create([
            'staff_user_id' => $staff->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla/5.0 Test',
            'device' => 'Chrome on Windows',
            'status' => 'success',
            'created_at' => now(),
        ]);

        // Staff views own login history
        $this->asToken($staffToken)->getJson('/api/v1/staff/login-history')
            ->assertOk()
            ->assertJsonPath('data.0.device', 'Chrome on Windows')
            ->assertJsonPath('data.0.status', 'success');

        // Staff cannot view member login history (admin only)
        $this->asToken($staffToken)->getJson("/api/v1/staff/members/{$staff->id}/login-history")
            ->assertForbidden();

        // Admin views member login history
        $this->asToken($adminToken)->getJson("/api/v1/staff/members/{$staff->id}/login-history")
            ->assertOk()
            ->assertJsonPath('data.0.device', 'Chrome on Windows');
    }

    public function test_granular_permissions_are_enforced_and_token_revoked_on_update(): void
    {
        $admin = StaffUser::factory()->admin()->withTwoFactor()->create();
        $adminToken = $admin->createToken('test', $admin->tokenAbilities())->plainTextToken;

        // Staff with only catalog permissions (no orders:manage)
        $staff = StaffUser::factory()->withTwoFactor()->create([
            'permissions' => ['catalog:view', 'catalog:manage'],
        ]);
        $staffToken = $staff->createToken('test', $staff->tokenAbilities())->plainTextToken;

        $order = Order::factory()->create(['status' => 'processing']);

        // Attempting to change order status without orders:manage is 403
        $this->asToken($staffToken)->postJson("/api/v1/staff/orders/{$order->id}/status", [
            'status' => 'shipped',
        ])->assertForbidden();

        // Admin updates staff permissions
        $this->asToken($adminToken)->patchJson("/api/v1/staff/members/{$staff->id}", [
            'permissions' => ['orders:view', 'orders:manage', 'catalog:view', 'catalog:manage'],
        ])->assertOk()->assertJsonPath('data.permissions', ['orders:view', 'orders:manage', 'catalog:view', 'catalog:manage']);

        // The old token was revoked on permission change!
        $this->asToken($staffToken)->postJson("/api/v1/staff/orders/{$order->id}/status", [
            'status' => 'shipped',
        ])->assertUnauthorized();

        // Staff re-authenticates to get updated token abilities
        $freshStaff = $staff->fresh();
        $freshToken = $freshStaff->createToken('test', $freshStaff->tokenAbilities())->plainTextToken;

        $this->asToken($freshToken)->postJson("/api/v1/staff/orders/{$order->id}/status", [
            'status' => 'shipped',
        ])->assertOk();
    }
}

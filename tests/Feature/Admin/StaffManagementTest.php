<?php

namespace Tests\Feature\Admin;

use App\Enums\StaffRole;
use App\Models\AdminAuditLog;
use App\Models\StaffUser;
use App\Notifications\StaffInvitationNotification;
use App\Services\Staff\StaffManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    private StaffUser $admin;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = StaffUser::factory()->admin()->withTwoFactor()->create();
        $this->adminToken = $this->admin->createToken('t', $this->admin->tokenAbilities())->plainTextToken;
    }

    private function asAdmin(): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->adminToken);
    }

    public function test_invited_staff_sets_own_password_with_the_emailed_code_then_logs_in(): void
    {
        $id = $this->asAdmin()->postJson('/api/v1/staff/members', ['name' => 'Vanna', 'email' => 'Vanna@Shop.test', 'role' => 'staff'])
            ->assertCreated()->assertJsonPath('data.email', 'vanna@shop.test')->assertJsonPath('data.is_active', true)->json('data.id');
        $member = StaffUser::find($id);

        $code = null;
        Notification::assertSentTo($member, StaffInvitationNotification::class, function ($n) use (&$code) {
            $code = $n->code;

            return $n->ttlHours === 72;
        });

        // Still valid after 2 days (a normal reset code expires after 15 minutes).
        $this->travel(2)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken('')->postJson('/api/v1/staff/auth/reset-password', [
            'email' => 'vanna@shop.test', 'code' => $code, 'password' => 'mypass123', 'password_confirmation' => 'mypass123',
        ])->assertOk();

        $this->postJson('/api/v1/staff/auth/login', ['email' => 'vanna@shop.test', 'password' => 'mypass123'])
            ->assertOk()->assertJsonPath('two_factor_setup_required', true);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'staff_user.created', 'entity_id' => $id]);
    }

    public function test_invitation_expires_and_can_be_resent(): void
    {
        $member = StaffUser::factory()->create(['email' => 'new@shop.test']);
        $this->asAdmin()->postJson("/api/v1/staff/members/{$member->id}/resend-invitation")->assertOk();
        $old = Notification::sent($member, StaffInvitationNotification::class)->last()->code;

        $this->travel(73)->hours();
        $this->withToken('')->postJson('/api/v1/staff/auth/reset-password', [
            'email' => 'new@shop.test', 'code' => $old, 'password' => 'mypass123', 'password_confirmation' => 'mypass123',
        ])->assertUnprocessable();
    }

    public function test_role_change_revokes_tokens_so_old_permissions_stop_working(): void
    {
        $other = StaffUser::factory()->admin()->withTwoFactor()->create();
        $otherToken = $other->createToken('t', $other->tokenAbilities())->plainTextToken;

        $this->asAdmin()->patchJson("/api/v1/staff/members/{$other->id}", ['role' => 'staff'])->assertOk()->assertJsonPath('data.role', 'staff');

        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->getJson('/api/v1/staff/members')->assertUnauthorized();
        $this->assertSame(StaffRole::Staff, $other->fresh()->role);
    }

    public function test_deactivate_and_activate_with_tokens_revoked(): void
    {
        $member = StaffUser::factory()->withTwoFactor()->create();
        $member->createToken('t', $member->tokenAbilities());

        $this->asAdmin()->postJson("/api/v1/staff/members/{$member->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame(0, $member->tokens()->count());
        $this->asAdmin()->postJson("/api/v1/staff/members/{$member->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);

        $this->assertSame(['staff_user.deactivated', 'staff_user.activated'], AdminAuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_reset_two_factor_for_lost_phone(): void
    {
        $member = StaffUser::factory()->withTwoFactor()->create();

        $this->asAdmin()->postJson("/api/v1/staff/members/{$member->id}/reset-two-factor")->assertOk()->assertJsonPath('data.two_factor_enabled', false);

        $this->assertNull($member->fresh()->two_factor_secret);
    }

    public function test_admins_cannot_change_or_disable_themselves(): void
    {
        $self = $this->admin->id;

        $this->asAdmin()->patchJson("/api/v1/staff/members/{$self}", ['role' => 'staff'])
            ->assertUnprocessable()->assertJsonPath('errors.staff.0', 'You cannot change your own role.');
        $this->asAdmin()->postJson("/api/v1/staff/members/{$self}/deactivate")
            ->assertUnprocessable()->assertJsonPath('errors.staff.0', 'You cannot deactivate your own account.');
        $this->asAdmin()->postJson("/api/v1/staff/members/{$self}/reset-two-factor")->assertUnprocessable();
        $this->asAdmin()->patchJson("/api/v1/staff/members/{$self}", ['name' => 'New Name'])->assertOk();
    }

    public function test_two_admins_demoting_each_other_at_once_leaves_one_admin(): void
    {
        $b = StaffUser::factory()->admin()->withTwoFactor()->create();
        $service = app(StaffManagementService::class);
        // Both requests were authenticated while both were still admins.
        $aSeenByB = StaffUser::find($this->admin->id);
        $bAsActor = StaffUser::find($b->id);

        $service->update($this->admin, $b, ['role' => 'staff']); // A demotes B first

        try {
            $service->update($bAsActor, $aSeenByB, ['role' => 'staff']); // B's request runs right after
            $this->fail('The last active admin was demoted.');
        } catch (ValidationException $e) {
            $this->assertSame('The last active admin cannot be demoted.', $e->errors()['staff'][0]);
        }

        $this->assertTrue(StaffUser::where('role', 'admin')->where('is_active', true)->sole()->is($this->admin));
    }

    public function test_list_and_validation_and_access(): void
    {
        StaffUser::factory()->create(['name' => 'Bopha', 'email' => 'bopha@shop.test']);
        StaffUser::factory()->create(['is_active' => false]);

        $this->asAdmin()->getJson('/api/v1/staff/members?q=bopha')->assertOk()->assertJsonCount(1, 'data');
        $this->asAdmin()->getJson('/api/v1/staff/members?active=0')->assertJsonCount(1, 'data');
        $this->asAdmin()->getJson('/api/v1/staff/members?role=admin')->assertJsonCount(1, 'data');
        $this->asAdmin()->postJson('/api/v1/staff/members', ['name' => 'X', 'email' => 'bopha@shop.test', 'role' => 'owner'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'role']);

        $staff = StaffUser::factory()->withTwoFactor()->create();
        $this->app['auth']->forgetGuards();
        $this->withToken($staff->createToken('t', $staff->tokenAbilities())->plainTextToken)->getJson('/api/v1/staff/members')->assertForbidden();
    }
}

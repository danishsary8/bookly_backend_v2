<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\Customer;
use App\Notifications\AccountReopenedNotification;
use App\Services\Customers\ClosedCustomers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class ReopenClosedAccountsTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function closed(array $attributes = [], int $daysAgo = 3): Customer
    {
        $customer = Customer::factory()->create(['password_hash' => 'reading123', ...$attributes]);
        $this->travel(-$daysAgo)->days();
        app(ClosedCustomers::class)->close($customer);
        $this->travelBack();

        return $customer->fresh() ?? Customer::withTrashed()->find($customer->id);
    }

    public function test_staff_see_closed_accounts_in_their_own_view(): void
    {
        Customer::factory()->create(['name' => 'Open Reader']);
        $recent = $this->closed(['name' => 'Sok Dara', 'email' => 'dara@example.com'], 3);
        $this->closed(['name' => 'Long Gone'], 31); // past its 30 days: not listed
        $staff = $this->staffToken();

        $this->asToken($staff)->getJson('/api/v1/staff/customers')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.counts.closed', 1);
        $this->asToken($staff)->getJson('/api/v1/staff/customers?closed=1')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $recent->id)
            ->assertJsonPath('data.0.email', 'dara@example.com')
            ->assertJsonPath('data.0.closed_at', fn ($at) => $at !== null)
            ->assertJsonPath('data.0.erase_at', fn ($at) => str_starts_with($at, now()->addDays(27)->toDateString()));
        $this->asToken($staff)->getJson('/api/v1/staff/customers?closed=1&q=nobody')->assertJsonCount(0, 'data')->assertJsonPath('meta.counts.closed', 0);

        // The detail page works for a closed account too.
        $this->asToken($staff)->getJson("/api/v1/staff/customers/{$recent->id}")->assertOk()->assertJsonPath('data.erase_at', fn ($at) => $at !== null);
    }

    public function test_an_admin_reopens_a_closed_account_and_it_is_audit_logged(): void
    {
        $customer = $this->closed(['email' => 'dara@example.com']);
        $admin = $this->staffToken(admin: true);

        $this->asToken($admin)->postJson("/api/v1/staff/customers/{$customer->id}/reopen")->assertOk()
            ->assertJsonPath('data.closed_at', null)
            ->assertJsonPath('data.erase_at', null);

        $this->assertNotSoftDeleted($customer);
        $log = AdminAuditLog::where('action', 'customer.reopened')->sole();
        $this->assertSame($customer->id, (int) $log->entity_id);
        $this->assertNull($log->after_data['closed_at']);
        Notification::assertSentTo($customer, AccountReopenedNotification::class);
        // The customer signs in again as before.
        $this->postJson('/api/v1/auth/login', ['email' => 'dara@example.com', 'password' => 'reading123'])->assertOk();
    }

    public function test_staff_who_are_not_admins_cannot_reopen(): void
    {
        $customer = $this->closed();

        $this->asToken($this->staffToken())->postJson("/api/v1/staff/customers/{$customer->id}/reopen")->assertForbidden();
        $this->assertSoftDeleted($customer);
    }

    public function test_after_30_days_it_cannot_be_reopened(): void
    {
        $customer = $this->closed([], 31);

        $this->asToken($this->staffToken(admin: true))->postJson("/api/v1/staff/customers/{$customer->id}/reopen")
            ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, "can't be reopened"));
        $this->assertSoftDeleted($customer);
        Notification::assertNothingSent();
    }

    public function test_an_open_account_is_not_reopened(): void
    {
        $customer = Customer::factory()->create();

        $this->asToken($this->staffToken(admin: true))->postJson("/api/v1/staff/customers/{$customer->id}/reopen")
            ->assertUnprocessable()->assertJsonPath('message', 'This account is open.');
        $this->assertSame(0, AdminAuditLog::count());
    }

    public function test_a_phone_only_account_reopens_without_an_email(): void
    {
        $customer = $this->closed(['email' => null, 'email_verified_at' => null, 'password_hash' => null, 'facebook_id' => 'fb-1', 'phone_e164' => '+85512345678', 'phone_verified_at' => now()]);

        $this->asToken($this->staffToken(admin: true))->postJson("/api/v1/staff/customers/{$customer->id}/reopen")->assertOk();
        $this->assertNotSoftDeleted($customer);
        Notification::assertNothingSent();
    }
}

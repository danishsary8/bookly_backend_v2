<?php

namespace Tests\Feature\Auth;

use App\Enums\VerificationPurpose;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Notifications\OtpCodeNotification;
use App\Services\Auth\OtpService;
use App\Services\Customers\UnverifiedCustomers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class UnfinishedSignupTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private const TAKEN = 'An account with this email already exists. Sign in, or reset your password if you forgot it.';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function register(array $body = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/register', [
            'name' => 'Dara', 'email' => 'dara@example.com', 'password' => 'secret123', 'password_confirmation' => 'secret123', ...$body,
        ]);
    }

    public function test_signing_up_again_takes_over_an_unfinished_sign_up(): void
    {
        $old = Customer::factory()->unverified()->create(['email' => 'dara@example.com', 'name' => 'Old', 'created_at' => now()->subHours(30)]);
        $oldToken = $old->createToken('t', ['customer'])->plainTextToken;

        $this->register(['name' => 'Sok Dara', 'email' => 'Dara@Example.com', 'password' => 'newpass123', 'password_confirmation' => 'newpass123'])
            ->assertCreated()
            ->assertJsonPath('customer.id', $old->id)
            ->assertJsonPath('customer.name', 'Sok Dara')
            ->assertJsonPath('customer.email_verified', false);

        $this->assertSame(1, Customer::count());
        $fresh = $old->fresh();
        $this->assertTrue(Hash::check('newpass123', $fresh->password_hash));
        $this->assertTrue($fresh->created_at->gt(now()->subMinute()), 'the 48 hours start again');
        $this->assertSame(1, $fresh->tokens()->count(), 'only the new session is left');
        $this->assertNull(PersonalAccessToken::findToken($oldToken));
        Notification::assertSentTo($fresh, OtpCodeNotification::class, fn ($n) => $n->purpose === VerificationPurpose::EmailVerify);
    }

    public function test_verified_deactivated_and_deleted_accounts_keep_their_email(): void
    {
        Customer::factory()->create(['email' => 'verified@example.com']);
        Customer::factory()->unverified()->create(['email' => 'off@example.com', 'is_active' => false]);
        Customer::factory()->unverified()->create(['email' => 'gone@example.com'])->delete();

        foreach (['verified@example.com', 'off@example.com', 'gone@example.com'] as $email) {
            $this->register(['email' => $email])->assertUnprocessable()->assertJsonPath('errors.email.0', self::TAKEN);
        }
        $this->assertSame(3, Customer::withTrashed()->count());
    }

    public function test_prune_removes_only_expired_unfinished_sign_ups(): void
    {
        $expired = Customer::factory()->unverified()->create(['created_at' => now()->subHours(49)]);
        $expired->createToken('t', ['customer']);
        app(OtpService::class)->issue($expired, VerificationPurpose::EmailVerify);
        $young = Customer::factory()->unverified()->create(['created_at' => now()->subHours(47)]);
        $verified = Customer::factory()->create(['created_at' => now()->subDays(10)]);
        $deactivated = Customer::factory()->unverified()->create(['created_at' => now()->subDays(10), 'is_active' => false]);
        $withAddress = Customer::factory()->unverified()->create(['created_at' => now()->subDays(10)]);
        CustomerAddress::factory()->for($withAddress)->create();

        $this->artisan('customers:prune-unverified')->expectsOutputToContain('Removed 1 unfinished sign-up(s) older than 48 hours.')->assertSuccessful();

        $this->assertNull(Customer::withTrashed()->find($expired->id));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $expired->id, 'tokenable_type' => $expired->getMorphClass()]);
        $this->assertDatabaseMissing('verification_tokens', ['user_type' => 'customer', 'user_id' => $expired->id]);
        foreach ([$young, $verified, $deactivated, $withAddress] as $kept) {
            $this->assertNotNull($kept->fresh());
        }
    }

    public function test_the_window_follows_the_setting(): void
    {
        config(['auth.unverified_customer_hours' => 24]);
        Customer::factory()->unverified()->create(['created_at' => now()->subHours(25)]);

        $this->artisan('customers:prune-unverified')->expectsOutputToContain('Removed 1')->assertSuccessful();
    }

    public function test_sign_up_prunes_at_most_once_an_hour(): void
    {
        Cache::forget('customers:unverified-pruned');
        $first = Customer::factory()->unverified()->create(['created_at' => now()->subDays(3)]);
        $this->register(['email' => 'one@example.com'])->assertCreated();
        $this->assertNull($first->fresh());

        $second = Customer::factory()->unverified()->create(['created_at' => now()->subDays(3)]);
        $this->register(['email' => 'two@example.com'])->assertCreated();
        $this->assertNotNull($second->fresh(), 'already pruned this hour');
    }

    public function test_staff_list_counts_both_tabs_and_shows_when_unfinished_sign_ups_go(): void
    {
        Cache::put('customers:unverified-pruned', true, now()->addHour());
        Customer::factory()->count(2)->create(['name' => 'Verified Reader']);
        $pending = Customer::factory()->unverified()->create(['name' => 'Pending Reader', 'created_at' => now()->subHours(40)]);
        $staff = $this->staffToken();

        $this->asToken($staff)->getJson('/api/v1/staff/customers?verified=0')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.removal_at', $pending->created_at->copy()->addHours(48)->toJSON())
            ->assertJsonPath('meta.counts', ['verified' => 2, 'unverified' => 1, 'closed' => 0])
            ->assertJsonPath('meta.total', 1);

        $this->asToken($staff)->getJson('/api/v1/staff/customers?verified=1&q=reader')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.removal_at', null)
            ->assertJsonPath('meta.counts', ['verified' => 2, 'unverified' => 1, 'closed' => 0]);

        $this->asToken($staff)->getJson('/api/v1/staff/customers?q=pending')->assertJsonPath('meta.counts', ['verified' => 0, 'unverified' => 1, 'closed' => 0]);
    }

    public function test_an_admin_can_delete_an_unfinished_sign_up_now(): void
    {
        $pending = Customer::factory()->unverified()->create(['email' => 'free-me@example.com']);
        $pending->createToken('t', ['customer']);
        $admin = $this->staffToken(admin: true);

        $this->asToken($admin)->deleteJson("/api/v1/staff/customers/{$pending->id}")->assertNoContent();

        $this->assertNull(Customer::withTrashed()->find($pending->id));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $pending->id, 'tokenable_type' => $pending->getMorphClass()]);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'customer.deleted', 'entity_id' => $pending->id]);
        $this->register(['email' => 'free-me@example.com'])->assertCreated();
    }

    public function test_only_admins_delete_and_only_unfinished_sign_ups_without_anything_attached(): void
    {
        $pending = Customer::factory()->unverified()->create();
        $verified = Customer::factory()->create();
        $withAddress = Customer::factory()->unverified()->create();
        CustomerAddress::factory()->for($withAddress)->create();

        $this->asToken($this->staffToken())->deleteJson("/api/v1/staff/customers/{$pending->id}")->assertForbidden();

        $admin = $this->staffToken(admin: true);
        $this->asToken($admin)->deleteJson("/api/v1/staff/customers/{$verified->id}")->assertUnprocessable()
            ->assertJsonPath('message', 'Only unfinished sign-ups can be deleted. Verified customers can be deactivated instead.');
        $this->asToken($admin)->deleteJson("/api/v1/staff/customers/{$withAddress->id}")->assertUnprocessable();
        $this->assertSame(3, Customer::count());
    }

    public function test_removal_time_is_null_for_kept_accounts(): void
    {
        $this->assertNull(UnverifiedCustomers::removalAt(Customer::factory()->create()));
        $this->assertNull(UnverifiedCustomers::removalAt(Customer::factory()->unverified()->create(['is_active' => false])));
    }
}

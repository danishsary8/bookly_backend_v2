<?php

namespace Tests\Feature\Admin;

use App\Models\Author;
use App\Models\BookVariant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class ExchangeRateAndAuditLogTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_admin_sets_rate_and_catalog_uses_it_while_scheduled_rates_wait(): void
    {
        $admin = $this->staffToken(admin: true);
        $variant = BookVariant::factory()->create(['price_usd' => '10.00']);

        $this->asToken($admin)->postJson('/api/v1/staff/exchange-rates', ['rate' => '4100'])->assertCreated()->assertJsonPath('data.is_current', true);
        $this->asToken($admin)->postJson('/api/v1/staff/exchange-rates', ['rate' => '4200', 'effective_at' => now()->addDay()->toIso8601String()])
            ->assertCreated()->assertJsonPath('data.is_current', false);

        $this->app->forgetScopedInstances();
        $this->getJson("/api/v1/books/{$variant->book_id}")->assertJsonPath('data.price_from_khr', '41000');
        $this->asToken($admin)->getJson('/api/v1/staff/exchange-rates')->assertOk()
            ->assertJsonPath('meta.current_rate', '4100.000000')->assertJsonCount(2, 'data');

        $this->travel(2)->days();
        $this->app->forgetScopedInstances();
        $this->getJson("/api/v1/books/{$variant->book_id}")->assertJsonPath('data.price_from_khr', '42000');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'exchange_rate.created']);
    }

    public function test_rate_validation_and_admin_only(): void
    {
        $this->asToken($this->staffToken(admin: true))->postJson('/api/v1/staff/exchange-rates', ['rate' => '0'])->assertUnprocessable();
        $this->asToken($this->staffToken())->postJson('/api/v1/staff/exchange-rates', ['rate' => '4100'])->assertForbidden();
    }

    public function test_audit_log_viewer_filters(): void
    {
        $adminUser = StaffUser::factory()->admin()->withTwoFactor()->create(['name' => 'Owner']);
        $admin = $adminUser->createToken('t', $adminUser->tokenAbilities())->plainTextToken;
        $staffToken = $this->staffToken();

        $this->asToken($staffToken)->postJson('/api/v1/staff/authors', ['name' => 'Author One'])->assertCreated();
        $author = Author::sole();
        $this->asToken($admin)->patchJson("/api/v1/staff/authors/{$author->id}", ['name' => 'Author Renamed'])->assertOk();
        $this->asToken($admin)->postJson('/api/v1/staff/exchange-rates', ['rate' => '4100'])->assertCreated();

        $this->asToken($admin)->getJson('/api/v1/staff/audit-logs')->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.action', 'exchange_rate.created')
            ->assertJsonPath('meta.entity_types', ['author', 'exchange_rate']);
        $this->asToken($admin)->getJson("/api/v1/staff/audit-logs?staff_user_id={$adminUser->id}")->assertJsonCount(2, 'data');
        $this->asToken($admin)->getJson("/api/v1/staff/audit-logs?entity_type=author&entity_id={$author->id}")->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.before.name', 'Author One')
            ->assertJsonPath('data.0.after.name', 'Author Renamed')
            ->assertJsonPath('data.0.staff.name', 'Owner');
        $this->asToken($admin)->getJson('/api/v1/staff/audit-logs?action=author.created')->assertJsonCount(1, 'data');
        $this->asToken($admin)->getJson('/api/v1/staff/audit-logs?from='.now()->addDay()->toDateString())->assertJsonCount(0, 'data');

        $this->asToken($staffToken)->getJson('/api/v1/staff/audit-logs')->assertForbidden();
    }
}

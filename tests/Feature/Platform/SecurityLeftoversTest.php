<?php

namespace Tests\Feature\Platform;

use App\Models\Customer;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class SecurityLeftoversTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_health_returns_only_status_ok_or_fail_without_details(): void
    {
        // 1. Working health
        $response = $this->getJson('/api/v1/health');
        $response->assertOk()
            ->assertExactJson(['status' => 'ok']);
        $this->assertArrayNotHasKey('checks', $response->json());

        // 2. Database down returns 503 with only status fail
        DB::partialMock()->shouldReceive('select')->with('select 1')->once()->andThrow(new RuntimeException('DB down'));
        $down = $this->getJson('/api/v1/health');
        $down->assertStatus(503)
            ->assertExactJson(['status' => 'fail']);
        $this->assertArrayNotHasKey('checks', $down->json());
    }

    public function test_staff_health_endpoint_permissions(): void
    {
        // Guest: 401
        $this->getJson('/api/v1/staff/health')->assertUnauthorized();

        // Customer: 403
        $customer = Customer::factory()->create();
        $custToken = $customer->createToken('t', ['customer'])->plainTextToken;
        $this->withToken($custToken)->getJson('/api/v1/staff/health')->assertForbidden();

        $this->app['auth']->forgetGuards();

        // Staff (non-admin): 403
        $staff = StaffUser::factory()->withTwoFactor()->create(['role' => 'staff']);
        $staffToken = $staff->createToken('t', ['staff'])->plainTextToken;
        $this->withToken($staffToken)->getJson('/api/v1/staff/health')->assertForbidden();

        $this->app['auth']->forgetGuards();

        // Admin: 200 with full checks
        $admin = StaffUser::factory()->admin()->withTwoFactor()->create();
        $adminToken = $admin->createToken('t', ['staff', 'admin'])->plainTextToken;
        $adminRes = $this->withToken($adminToken)->getJson('/api/v1/staff/health')->assertOk();
        $adminRes->assertJsonStructure([
            'status',
            'checks' => [
                'database' => ['status'],
                'queue' => ['status', 'driver'],
                'telegram_bot' => ['status'],
            ],
        ]);
    }

    public function test_docs_page_has_sri_integrity_and_crossorigin_attributes(): void
    {
        $response = $this->get('/docs');
        $response->assertOk();

        $content = $response->getContent();
        $this->assertStringContainsString('@scalar/api-reference@1.74.0', $content);
        $this->assertStringContainsString('integrity="sha384-', $content);
        $this->assertStringContainsString('crossorigin="anonymous"', $content);
    }

    public function test_customer_with_password_closing_account(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'reader@example.com',
            'email_verified_at' => now(),
            'password_hash' => Hash::make('Secret123!'),
        ]);
        $token = $customer->createToken('t', ['customer'])->plainTextToken;

        // Missing password -> 422
        $this->withToken($token)->deleteJson('/api/v1/me', [
            'confirm' => 'DELETE',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

        // Wrong password -> 422
        $this->withToken($token)->deleteJson('/api/v1/me', [
            'confirm' => 'DELETE',
            'password' => 'WrongPassword!',
        ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

        // Correct password -> 200, closed
        $this->withToken($token)->deleteJson('/api/v1/me', [
            'confirm' => 'DELETE',
            'password' => 'Secret123!',
        ])->assertOk()->assertJsonStructure(['message', 'erase_at']);

        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_telegram_only_customer_closing_account_with_fresh_token(): void
    {
        // Telegram-only customer: no password, no email, verified phone
        $customer = Customer::factory()->create([
            'email' => null,
            'email_verified_at' => null,
            'password_hash' => null,
            'phone' => '+85512345678',
            'phone_e164' => '+85512345678',
            'phone_verified_at' => now(),
        ]);

        $freshToken = $customer->createToken('t', ['customer'])->plainTextToken;

        // Fresh token (< 10 minutes old) can close account directly with confirm=DELETE
        $response = $this->withToken($freshToken)->deleteJson('/api/v1/me', [
            'confirm' => 'DELETE',
        ]);

        $response->assertOk()->assertJsonStructure(['message', 'erase_at']);
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_telegram_only_customer_closing_account_with_stale_token_requires_sign_in_again(): void
    {
        // Telegram-only customer
        $customer = Customer::factory()->create([
            'email' => null,
            'email_verified_at' => null,
            'password_hash' => null,
            'phone' => '+85512345679',
            'phone_e164' => '+85512345679',
            'phone_verified_at' => now(),
        ]);

        $tokenModel = $customer->createToken('t', ['customer']);
        // Simulate token created 15 minutes ago
        $tokenModel->accessToken->forceFill(['created_at' => now()->subMinutes(15)])->save();
        $staleToken = $tokenModel->plainTextToken;

        $response = $this->withToken($staleToken)->deleteJson('/api/v1/me', [
            'confirm' => 'DELETE',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('reason', 'sign_in_again')
            ->assertJsonPath('message', 'For your safety, sign in again first, then try this within 10 minutes.');

        $this->assertNull(Customer::find($customer->id)->deleted_at ?? null);
    }
}

<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSessionsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private function signIn(string $email, string $userAgent, string $ip, string $password = 'Secret123'): string
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['User-Agent' => $userAgent])->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])
            ->assertOk()->json('token');
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_a_customer_sees_each_signed_in_device_with_this_one_marked(): void
    {
        Customer::factory()->create(['email' => 'dara@example.test', 'password_hash' => 'Secret123', 'email_verified_at' => now()]);
        $laptop = $this->signIn('dara@example.test', self::CHROME_WINDOWS, '203.0.113.7');
        $this->signIn('dara@example.test', self::SAFARI_IPHONE, '198.51.100.9');

        $sessions = $this->as($laptop)->getJson('/api/v1/me/sessions')->assertOk()->json('data');

        $this->assertCount(2, $sessions);
        $byDevice = collect($sessions)->keyBy('device');
        $this->assertTrue($byDevice['Chrome on Windows']['is_current']);
        $this->assertSame('203.0.113.7', $byDevice['Chrome on Windows']['ip_address']);
        $this->assertFalse($byDevice['Safari on iPhone']['is_current']);
        $this->assertSame('198.51.100.9', $byDevice['Safari on iPhone']['ip_address']);
    }

    public function test_signing_out_one_device_or_all_others_keeps_this_device(): void
    {
        $dara = Customer::factory()->create(['email' => 'dara@example.test', 'password_hash' => 'Secret123', 'email_verified_at' => now()]);
        $laptop = $this->signIn('dara@example.test', self::CHROME_WINDOWS, '203.0.113.7');
        $phone = $this->signIn('dara@example.test', self::SAFARI_IPHONE, '198.51.100.9');
        $tablet = $this->signIn('dara@example.test', self::SAFARI_IPHONE, '198.51.100.10');
        $phoneId = (int) explode('|', $phone)[0];

        $this->as($laptop)->deleteJson("/api/v1/me/sessions/{$phoneId}")->assertOk();
        $this->as($phone)->getJson('/api/v1/me')->assertUnauthorized();
        $this->as($laptop)->deleteJson("/api/v1/me/sessions/{$phoneId}")->assertNotFound();

        // This device is signed out with the normal Sign out, not from the list.
        $laptopId = (int) explode('|', $laptop)[0];
        $this->as($laptop)->deleteJson("/api/v1/me/sessions/{$laptopId}")->assertUnprocessable();

        $this->as($laptop)->postJson('/api/v1/me/sessions/sign-out-others')->assertOk()->assertJsonPath('signed_out', 1);
        $this->as($tablet)->getJson('/api/v1/me')->assertUnauthorized();
        $this->as($laptop)->getJson('/api/v1/me')->assertOk();
        $this->assertSame(1, $dara->tokens()->count());
    }

    public function test_a_customer_cannot_sign_out_someone_elses_device(): void
    {
        Customer::factory()->create(['email' => 'dara@example.test', 'password_hash' => 'Secret123', 'email_verified_at' => now()]);
        Customer::factory()->create(['email' => 'sokha@example.test', 'password_hash' => 'Secret123', 'email_verified_at' => now()]);
        $dara = $this->signIn('dara@example.test', self::CHROME_WINDOWS, '203.0.113.7');
        $sokha = $this->signIn('sokha@example.test', self::SAFARI_IPHONE, '198.51.100.9');

        $this->as($dara)->deleteJson('/api/v1/me/sessions/'.explode('|', $sokha)[0])->assertNotFound();
        $this->as($sokha)->getJson('/api/v1/me')->assertOk();
        $this->assertCount(1, $this->as($dara)->getJson('/api/v1/me/sessions')->json('data'));
    }

    public function test_the_shared_demo_account_only_sees_its_own_session_and_cannot_sign_others_out(): void
    {
        $this->seed(DemoSeeder::class);
        $visitorA = $this->signIn(DemoSeeder::EMAIL, self::CHROME_WINDOWS, '203.0.113.7', DemoSeeder::PASSWORD);
        $visitorB = $this->signIn(DemoSeeder::EMAIL, self::SAFARI_IPHONE, '198.51.100.9', DemoSeeder::PASSWORD);

        $sessions = $this->as($visitorB)->getJson('/api/v1/me/sessions')->assertOk()->json('data');
        $this->assertCount(1, $sessions);
        $this->assertTrue($sessions[0]['is_current']);
        $this->assertSame('198.51.100.9', $sessions[0]['ip_address']);

        $this->as($visitorB)->postJson('/api/v1/me/sessions/sign-out-others')->assertForbidden();
        $this->as($visitorB)->deleteJson('/api/v1/me/sessions/'.explode('|', $visitorA)[0])->assertForbidden();
        $this->as($visitorA)->getJson('/api/v1/me')->assertOk();
    }

    public function test_older_sign_ins_without_a_device_name_show_none(): void
    {
        $dara = Customer::factory()->create(['email_verified_at' => now()]);
        $old = $dara->createToken('api', ['customer'])->plainTextToken;

        $this->as($old)->getJson('/api/v1/me/sessions')->assertOk()->assertJsonPath('data.0.device', null);
    }
}

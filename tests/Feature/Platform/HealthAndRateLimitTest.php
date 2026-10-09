<?php

namespace Tests\Feature\Platform;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthAndRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_is_ok_with_a_working_database_and_queue(): void
    {
        config(['queue.default' => 'database', 'services.telegram_bot.token' => null]);

        $this->getJson('/api/v1/health')->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonPath('checks.queue.pending', 0)
            ->assertJsonPath('checks.queue.oldest_pending_seconds', null);
    }

    public function test_old_unprocessed_jobs_mark_the_queue_degraded(): void
    {
        config(['queue.default' => 'database', 'services.telegram_bot.token' => null]);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->getTimestamp(), 'created_at' => now()->subMinutes(20)->getTimestamp()]);

        $this->getJson('/api/v1/health')->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.queue.status', 'degraded')
            ->assertJsonPath('checks.queue.pending', 1);
    }

    public function test_general_limit_is_120_per_minute_per_user(): void
    {
        $token = Customer::factory()->create()->createToken('t', ['customer'])->plainTextToken;

        for ($i = 0; $i < 120; $i++) {
            $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        }
        $this->withToken($token)->getJson('/api/v1/me')->assertTooManyRequests()->assertHeader('Retry-After');

        // Another user is not affected.
        $other = Customer::factory()->create()->createToken('t', ['customer'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/me')->assertOk();
    }
}

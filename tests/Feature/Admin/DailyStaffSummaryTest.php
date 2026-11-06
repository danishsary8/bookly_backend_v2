<?php

namespace Tests\Feature\Admin;

use App\Services\Telegram\OpsAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class DailyStaffSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_summary_is_sent_once_per_phnom_penh_day(): void
    {
        config(['services.ops_alerts.token' => 'test-only', 'services.ops_alerts.staff_chat_id' => '123']);
        Carbon::setTestNow('2026-10-10 13:05:00 UTC'); // 20:05 in Phnom Penh
        $alerts = Mockery::mock(OpsAlerts::class);
        $alerts->shouldReceive('sendDirect')->once()->with('123', Mockery::on(fn ($message) => str_contains($message, '2026-10-10') && str_contains($message, '$0.00')))->andReturn(['ok' => true]);
        $this->app->instance(OpsAlerts::class, $alerts);

        $this->artisan('ops:daily-summary')->assertSuccessful();
        $this->artisan('ops:daily-summary')->assertSuccessful();
        $this->assertDatabaseCount('staff_daily_summaries', 1);
    }

    public function test_unconfigured_group_does_not_claim_a_delivery(): void
    {
        config(['services.ops_alerts.token' => '', 'services.ops_alerts.staff_chat_id' => '']);
        $this->artisan('ops:daily-summary')->assertSuccessful();
        $this->assertDatabaseCount('staff_daily_summaries', 0);
    }

    public function test_catch_up_before_eight_targets_yesterday_and_does_not_repeat_an_uncertain_send(): void
    {
        config(['services.ops_alerts.token' => 'test-only', 'services.ops_alerts.staff_chat_id' => '123']);
        Carbon::setTestNow('2026-10-10 12:00:00 UTC');
        $alerts = Mockery::mock(OpsAlerts::class);
        $alerts->shouldReceive('sendDirect')->once()->with('123', Mockery::on(fn ($message) => str_contains($message, '2026-10-09')))->andReturn(['ok' => false]);
        $this->app->instance(OpsAlerts::class, $alerts);

        $this->artisan('ops:daily-summary')->assertSuccessful();
        $this->artisan('ops:daily-summary')->assertSuccessful();
        $this->assertDatabaseHas('staff_daily_summaries', ['summary_date' => '2026-10-09']);
    }
}

<?php

namespace Tests\Feature\Platform;

use App\Models\Customer;
use App\Services\Maintenance\DueMaintenance;
use App\Support\MaintenanceTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class DueMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Artisan::command('test:hourly', fn () => Cache::increment('test:hourly-ran'));
        Artisan::command('test:daily', fn () => Cache::increment('test:daily-ran'));
        Artisan::command('test:broken', function () {
            Cache::increment('test:broken-tried');
            throw new RuntimeException('boom');
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hourly(): array
    {
        return ['name' => 'hourly', 'command' => 'test:hourly', 'parameters' => [], 'cron' => '0 * * * *'];
    }

    private function daily(): array
    {
        return ['name' => 'daily', 'command' => 'test:daily', 'parameters' => [], 'cron' => '0 0 * * *'];
    }

    private function broken(): array
    {
        return ['name' => 'broken', 'command' => 'test:broken', 'parameters' => [], 'cron' => '0 * * * *'];
    }

    public function test_a_job_that_has_never_run_runs_at_the_first_check(): void
    {
        Carbon::setTestNow('2026-10-12 10:30:00');

        $this->assertSame(['hourly'], (new DueMaintenance([$this->hourly()]))->runDue());
        $this->assertSame(1, Cache::get('test:hourly-ran'));
    }

    public function test_it_does_not_run_again_until_the_next_scheduled_time(): void
    {
        $runner = new DueMaintenance([$this->hourly()]);

        Carbon::setTestNow('2026-10-12 10:30:00');
        $runner->runDue();

        Carbon::setTestNow('2026-10-12 10:50:00'); // same hour: nothing new was scheduled
        $this->assertSame([], $runner->runDue());
        $this->assertSame(1, Cache::get('test:hourly-ran'));

        Carbon::setTestNow('2026-10-12 11:05:00'); // 11:00 has passed since the last run
        $this->assertSame(['hourly'], $runner->runDue());
        $this->assertSame(2, Cache::get('test:hourly-ran'));
    }

    public function test_a_daily_job_catches_up_even_when_no_request_arrives_at_midnight(): void
    {
        $runner = new DueMaintenance([$this->daily()]);

        Carbon::setTestNow('2026-10-11 20:00:00');
        $runner->runDue();

        Carbon::setTestNow('2026-10-11 23:30:00'); // still before the next midnight
        $this->assertSame([], $runner->runDue());

        Carbon::setTestNow('2026-10-12 15:07:00'); // a random afternoon request after midnight
        $this->assertSame(['daily'], $runner->runDue());
        $this->assertSame(2, Cache::get('test:daily-ran'));
    }

    public function test_it_checks_at_most_once_a_minute(): void
    {
        $runner = new DueMaintenance([$this->hourly()]);

        Carbon::setTestNow('2026-10-12 10:30:00');
        $runner->runDue();
        Cache::forget('maintenance:last-run:hourly');
        Cache::forget('maintenance:lock:hourly');

        Carbon::setTestNow('2026-10-12 10:30:40'); // same minute window: no new check
        $this->assertSame([], $runner->runDue());

        Carbon::setTestNow('2026-10-12 10:31:10');
        $this->assertSame(['hourly'], $runner->runDue());
    }

    public function test_a_failing_job_never_breaks_the_caller_and_is_retried_later_not_at_once(): void
    {
        $runner = new DueMaintenance([$this->broken(), $this->hourly()]);

        Carbon::setTestNow('2026-10-12 10:30:00');
        $this->assertSame(['hourly'], $runner->runDue(), 'the other job still runs');
        $this->assertSame(1, Cache::get('test:broken-tried'));

        Carbon::setTestNow('2026-10-12 10:35:00'); // within 10 minutes: held back
        $runner->runDue();
        $this->assertSame(1, Cache::get('test:broken-tried'));

        Carbon::setTestNow('2026-10-12 10:42:00'); // lock expired: tried again
        $runner->runDue();
        $this->assertSame(2, Cache::get('test:broken-tried'));
    }

    public function test_a_job_that_is_already_running_is_left_alone(): void
    {
        Carbon::setTestNow('2026-10-12 10:30:00');
        Cache::put('maintenance:lock:hourly', true, now()->addMinutes(5));

        $this->assertSame([], (new DueMaintenance([$this->hourly()]))->runDue());
        $this->assertNull(Cache::get('test:hourly-ran'));
    }

    public function test_it_does_nothing_unless_switched_on(): void
    {
        $this->app->instance(DueMaintenance::class, new DueMaintenance([$this->hourly()]));
        config(['shop.maintenance_on_request' => false]);

        $this->getJson('/api/v1/ping')->assertOk();

        $this->assertNull(Cache::get('test:hourly-ran'));
    }

    public function test_an_api_request_runs_due_jobs_after_the_response_when_switched_on(): void
    {
        $this->app->instance(DueMaintenance::class, new DueMaintenance([$this->hourly()]));
        config(['shop.maintenance_on_request' => true]);

        $this->getJson('/api/v1/ping')->assertOk()->assertJson(['status' => 'ok']);
        $this->assertSame(1, Cache::get('test:hourly-ran'));

        $this->getJson('/api/v1/ping')->assertOk(); // already done this hour
        $this->assertSame(1, Cache::get('test:hourly-ran'));
    }

    public function test_a_broken_job_does_not_change_the_response(): void
    {
        $this->app->instance(DueMaintenance::class, new DueMaintenance([$this->broken()]));
        config(['shop.maintenance_on_request' => true]);

        $this->getJson('/api/v1/ping')->assertOk()->assertJson(['status' => 'ok']);
        $this->assertSame(1, Cache::get('test:broken-tried'));
    }

    public function test_browser_preflight_requests_do_not_trigger_it(): void
    {
        $this->app->instance(DueMaintenance::class, new DueMaintenance([$this->hourly()]));
        config(['shop.maintenance_on_request' => true]);

        $this->call('OPTIONS', '/api/v1/ping');

        $this->assertNull(Cache::get('test:hourly-ran'));
    }

    public function test_every_registered_job_is_a_real_command_that_runs_cleanly(): void
    {
        foreach (MaintenanceTasks::all() as $task) {
            $this->assertSame(0, Artisan::call($task['command'], $task['parameters']), $task['command'].' failed: '.Artisan::output());
        }
    }

    public function test_the_shared_demo_account_is_reset_daily(): void
    {
        $this->assertContains(['name' => 'demo-reset', 'command' => 'demo:reset', 'parameters' => [], 'cron' => '0 3 * * *'], MaintenanceTasks::all());
    }

    public function test_the_scheduler_lists_exactly_the_registered_jobs(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        foreach (MaintenanceTasks::all() as $task) {
            $this->assertStringContainsString($task['command'], $output);
            $this->assertStringContainsString($task['cron'], $output);
        }
    }

    public function test_registered_commands_exist_in_web_requests_too(): void
    {
        // Some packages register their commands only when running in a terminal; those cannot be called from a
        // request. App and framework commands are always available.
        foreach (MaintenanceTasks::all() as $task) {
            $command = Artisan::all()[$task['command']] ?? null;
            $this->assertNotNull($command, $task['command'].' is not registered');
            $this->assertMatchesRegularExpression('/^(App|Illuminate)\\\\/', $command::class, $task['command'].' comes from a package that may not register it in web requests');
        }
    }

    public function test_the_token_clean_up_deletes_only_tokens_expired_beyond_the_grace_period(): void
    {
        config(['sanctum.expiration' => 60 * 24 * 7]);
        $customer = Customer::factory()->create();

        $old = $customer->createToken('old', ['*'], now()->subDays(3))->accessToken;       // expired 3 days ago
        $recent = $customer->createToken('recent', ['*'], now()->subHours(2))->accessToken; // expired 2 hours ago: kept
        $valid = $customer->createToken('valid', ['*'], now()->addDay())->accessToken;
        $ancient = $customer->createToken('no-expiry-ancient', ['*']);
        $ancient->accessToken->forceFill(['expires_at' => null, 'created_at' => now()->subDays(30)])->save();

        $this->artisan('tokens:prune-expired --hours=24')->expectsOutputToContain('Deleted 2')->assertSuccessful();

        $this->assertNull($old->fresh());
        $this->assertNull($ancient->accessToken->fresh());
        $this->assertNotNull($recent->fresh());
        $this->assertNotNull($valid->fresh());
    }
}

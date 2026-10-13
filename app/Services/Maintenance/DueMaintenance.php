<?php

namespace App\Services\Maintenance;

use App\Support\MaintenanceTasks;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runs the housekeeping jobs from web requests, for hosts without a scheduler (Render's free plan).
 *
 * `schedule:run` only fires a job when the current minute matches its schedule, which a request that comes in at
 * a random time almost never does. So this catches up instead: a job is due when a scheduled time has passed since
 * it last ran. The uptime monitor's 5-minute ping therefore keeps every job running on time. It is called after
 * the response (see RunDueMaintenance), never breaks a request, and does nothing unless
 * RUN_MAINTENANCE_ON_REQUEST=true.
 */
class DueMaintenance
{
    /** At most one check a minute, across all workers. */
    private const CHECK_EVERY_MINUTES = 1;

    /** A job that is running or just failed is left alone for this long (so a broken job is retried, not hammered). */
    private const LOCK_MINUTES = 10;

    /**
     * @param  list<array{name: string, command: string, parameters: array<string, mixed>, cron: string}>|null  $tasks
     */
    public function __construct(private ?array $tasks = null) {}

    /** @return list<string> names of the jobs that ran */
    public function runIfEnabled(): array
    {
        if (! config('shop.maintenance_on_request')) {
            return [];
        }

        return $this->runDue();
    }

    /** @return list<string> names of the jobs that ran */
    public function runDue(): array
    {
        $ran = [];

        try {
            if (! Cache::add('maintenance:checked', true, now()->addMinutes(self::CHECK_EVERY_MINUTES))) {
                return [];
            }

            foreach ($this->tasks ?? MaintenanceTasks::all() as $task) {
                if ($this->runIfDue($task)) {
                    $ran[] = $task['name'];
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $ran;
    }

    /** @param  array{name: string, command: string, parameters: array<string, mixed>, cron: string}  $task */
    private function runIfDue(array $task): bool
    {
        $lastKey = 'maintenance:last-run:'.$task['name'];

        $shouldHaveRun = Carbon::instance((new CronExpression($task['cron']))->getPreviousRunDate(now(), 0, true));
        $last = Cache::get($lastKey);
        if ($last !== null && Carbon::parse($last)->gte($shouldHaveRun)) {
            return false;
        }

        // One process at a time; the lock also holds back a retry after a failure.
        if (! Cache::add('maintenance:lock:'.$task['name'], true, now()->addMinutes(self::LOCK_MINUTES))) {
            return false;
        }

        try {
            Artisan::call($task['command'], $task['parameters']);
            Cache::forever($lastKey, now()->toIso8601String());

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}

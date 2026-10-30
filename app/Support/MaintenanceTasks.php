<?php

namespace App\Support;

/**
 * The recurring housekeeping jobs, in one place. routes/console.php schedules them (hosts with a scheduler), and
 * App\Services\Maintenance\DueMaintenance runs the same list from web requests (the free host has no scheduler).
 */
class MaintenanceTasks
{
    /**
     * @return list<array{name: string, command: string, parameters: array<string, mixed>, cron: string}>
     */
    public static function all(): array
    {
        return [
            // Expired API tokens stop working at expiry; this just keeps the table small. (Our own command: Sanctum's
            // `sanctum:prune-expired` is not registered during web requests.)
            ['name' => 'sanctum-tokens', 'command' => 'tokens:prune-expired', 'parameters' => ['--hours' => 24], 'cron' => '0 0 * * *'],
            // Sign-ups that were never verified.
            ['name' => 'unverified-customers', 'command' => 'customers:prune-unverified', 'parameters' => [], 'cron' => '0 * * * *'],
            // Personal details of accounts closed more than 30 days ago.
            ['name' => 'closed-customers', 'command' => 'customers:erase-closed', 'parameters' => [], 'cron' => '0 0 * * *'],
            // Failed jobs are kept a week for debugging, finished and stuck job batches a few days.
            ['name' => 'failed-jobs', 'command' => 'queue:prune-failed', 'parameters' => ['--hours' => 168], 'cron' => '0 0 * * *'],
            ['name' => 'job-batches', 'command' => 'queue:prune-batches', 'parameters' => ['--hours' => 48, '--unfinished' => 72], 'cron' => '0 0 * * *'],
            // Marketing outbox (runs every minute, capped daily).
            ['name' => 'marketing-outbox', 'command' => 'marketing:send', 'parameters' => [], 'cron' => '* * * * *'],
            // Permanently purge soft-deleted catalog items trashed for more than 30 days.
            // The docs page lets visitors sign in as the shared demo customer: keep that account usable for the next one.
            ['name' => 'demo-reset', 'command' => 'demo:reset', 'parameters' => [], 'cron' => '0 3 * * *'],
            ['name' => 'purge-trash', 'command' => 'trash:purge', 'parameters' => [], 'cron' => '0 0 * * *'],
        ];
    }
}

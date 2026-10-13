<?php

use App\Support\MaintenanceTasks;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The housekeeping jobs (expired tokens, unfinished sign-ups, closed accounts, old failed jobs) are listed in
// App\Support\MaintenanceTasks. Where a scheduler runs (`schedule:work`) this schedules them; on the free host, which
// has none, App\Services\Maintenance\DueMaintenance runs the same list from web requests.
foreach (MaintenanceTasks::all() as $task) {
    Schedule::command($task['command'], $task['parameters'])->cron($task['cron']);
}

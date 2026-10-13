<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\Sanctum;

/**
 * Same clean-up as Sanctum's own `sanctum:prune-expired`, but always available: Sanctum registers its command only
 * when running in a terminal, so the web-request housekeeping (App\Services\Maintenance\DueMaintenance) cannot call it.
 */
class PruneExpiredTokens extends Command
{
    protected $signature = 'tokens:prune-expired {--hours=24 : How many hours to keep expired tokens}';

    protected $description = 'Delete API tokens that expired more than the given number of hours ago (they stop working at expiry; this keeps the table small)';

    public function handle(): int
    {
        $model = Sanctum::$personalAccessTokenModel;
        $hours = (int) $this->option('hours');

        $deleted = $model::where('expires_at', '<', now()->subHours($hours))->delete();

        // Tokens without their own expiry date follow the global SANCTUM_EXPIRATION (in minutes).
        if ($expiration = config('sanctum.expiration')) {
            $deleted += $model::whereNull('expires_at')
                ->where('created_at', '<', now()->subMinutes($expiration + ($hours * 60)))
                ->delete();
        }

        $this->info("Deleted {$deleted} expired token(s) older than {$hours} hour(s) past expiry.");

        return self::SUCCESS;
    }
}

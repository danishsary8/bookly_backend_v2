<?php

namespace App\Console\Commands;

use App\Services\Customers\UnverifiedCustomers;
use Illuminate\Console\Command;

class PruneUnverifiedCustomers extends Command
{
    protected $signature = 'customers:prune-unverified';

    protected $description = 'Delete sign-ups that were never verified, once they are older than UNVERIFIED_CUSTOMER_HOURS (48 by default)';

    public function handle(UnverifiedCustomers $unverified): int
    {
        $removed = $unverified->prune();
        $this->info("Removed {$removed} unfinished sign-up(s) older than ".UnverifiedCustomers::hours().' hours.');

        return self::SUCCESS;
    }
}

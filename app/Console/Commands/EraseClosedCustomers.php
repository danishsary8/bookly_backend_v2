<?php

namespace App\Console\Commands;

use App\Services\Customers\ClosedCustomers;
use Illuminate\Console\Command;

class EraseClosedCustomers extends Command
{
    protected $signature = 'customers:erase-closed';

    protected $description = 'Erase the personal details of accounts closed more than CLOSED_ACCOUNT_DAYS (30) ago; orders are kept';

    public function handle(ClosedCustomers $closed): int
    {
        $erased = $closed->erase();
        $this->info("Erased {$erased} closed account(s) older than ".ClosedCustomers::days().' days.');

        return self::SUCCESS;
    }
}

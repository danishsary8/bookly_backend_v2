<?php

namespace App\Console\Commands;

use App\Services\Marketing\CartReminders;
use Illuminate\Console\Command;

class SendCartReminders extends Command
{
    protected $signature = 'cart:remind';

    protected $description = 'Queue one reminder email for carts left alone for a day (sent by marketing:send)';

    public function handle(CartReminders $reminders): int
    {
        $this->info('Cart reminders queued: '.$reminders->queueDue().'.');

        return self::SUCCESS;
    }
}

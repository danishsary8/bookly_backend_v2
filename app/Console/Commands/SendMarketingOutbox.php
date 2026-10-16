<?php

namespace App\Console\Commands;

use App\Services\Marketing\MarketingOutboxService;
use Illuminate\Console\Command;

class SendMarketingOutbox extends Command
{
    protected $signature = 'marketing:send {--limit=10 : Maximum number of pending emails to send}';

    protected $description = 'Send pending marketing outbox emails within daily limits';

    public function handle(MarketingOutboxService $service): int
    {
        $limit = (int) $this->option('limit');
        $sent = $service->sendPending($limit > 0 ? $limit : 10);

        $this->info("Processed marketing outbox. Sent: {$sent}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Orders\PreorderAllocationService;
use Illuminate\Console\Command;

class CheckPreorderReleases extends Command
{
    protected $signature = 'preorders:check-releases';

    protected $description = 'Checks pre-orders whose release date has arrived, notifies customers and allocates waiting orders';

    public function handle(PreorderAllocationService $service): int
    {
        $count = $service->releasePreorders();
        $this->info("Processed {$count} released pre-order variant(s).");

        return self::SUCCESS;
    }
}

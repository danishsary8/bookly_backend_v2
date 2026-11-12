<?php

namespace App\Console\Commands;

use App\Services\Rewards\RewardCoupons;
use Illuminate\Console\Command;

class SendRewardReminders extends Command
{
    protected $signature = 'rewards:remind';

    protected $description = 'Email customers whose unused reward coupon ends within 3 days (once per coupon)';

    public function handle(RewardCoupons $rewards): int
    {
        $this->info('Reward reminders sent: '.$rewards->remindEnding().'.');

        return self::SUCCESS;
    }
}

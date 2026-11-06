<?php

namespace App\Console\Commands;

use App\Services\Credit\Referrals;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RewardReferrals extends Command
{
    protected $signature = 'referrals:reward';

    protected $description = 'Reward eligible first orders after the seven-day delivery hold';

    public function handle(Referrals $referrals): int
    {
        if (config('shop.referrals_enabled')) {
            DB::table('referrals')->where('status', 'qualified')->orderBy('id')->chunkById(100, function ($rows) use ($referrals) {
                foreach ($rows as $row) {
                    $referrals->reward($row->id);
                }
            });
        }

        return self::SUCCESS;
    }
}

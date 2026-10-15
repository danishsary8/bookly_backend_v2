<?php

namespace App\Services\Marketing;

use App\Models\MarketingOutbox;
use App\Notifications\BackInStockNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

class MarketingOutboxService
{
    /**
     * Sends pending marketing emails, up to $limit per run, strictly capped at MARKETING_DAILY_LIMIT per shop-time calendar day.
     */
    public function sendPending(int $limit = 10): int
    {
        $lock = Cache::lock('marketing:send:lock', 30);

        return $lock->get(function () use ($limit) {
            $timezone = config('shop.timezone', 'Asia/Phnom_Penh');
            $todayStart = Carbon::now($timezone)->startOfDay()->setTimezone('UTC');
            $todayEnd = Carbon::now($timezone)->endOfDay()->setTimezone('UTC');

            $sentToday = MarketingOutbox::where('status', 'sent')
                ->whereBetween('sent_at', [$todayStart, $todayEnd])
                ->count();

            $dailyLimit = (int) config('shop.marketing_daily_limit', 60);
            $remainingQuota = max(0, $dailyLimit - $sentToday);

            if ($remainingQuota <= 0) {
                return 0;
            }

            $batchSize = min($limit, $remainingQuota);

            $pending = MarketingOutbox::where('status', 'pending')
                ->orderBy('id', 'asc')
                ->limit($batchSize)
                ->get();

            $sentCount = 0;

            foreach ($pending as $row) {
                $row->increment('attempts');

                try {
                    if ($row->kind === 'back_in_stock') {
                        Notification::route('mail', $row->email)
                            ->notify((new BackInStockNotification($row->payload))->locale($row->locale));
                    }

                    $row->update([
                        'status' => 'sent',
                        'sent_at' => now(),
                        'last_error' => null,
                    ]);
                    $sentCount++;
                } catch (Throwable $e) {
                    report($e);
                    $newStatus = $row->attempts >= 3 ? 'failed' : 'pending';
                    $row->update([
                        'status' => $newStatus,
                        'last_error' => Str::limit($e->getMessage(), 1000),
                    ]);
                }
            }

            return $sentCount;
        }) ?? 0;
    }
}

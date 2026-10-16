<?php

namespace App\Services\Marketing;

use App\Models\MarketingOutbox;
use App\Models\NewsletterCampaign;
use App\Models\NewsletterSubscriber;
use App\Notifications\BackInStockNotification;
use App\Notifications\NewsletterCampaignNotification;
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
        // Rows are claimed (status `sending`) before any mail goes out, so even a second run after the lock expired can't
        // send them twice; a run that died mid-way leaves rows in `sending`, put back after 15 minutes.
        MarketingOutbox::where('status', 'sending')->where('updated_at', '<', now()->subMinutes(15))->update(['status' => 'pending']);
        $lock = Cache::lock('marketing:send:lock', 300);

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

            $sendingNow = MarketingOutbox::where('status', 'sending')->count();
            $batchSize = min($batchSize, max(0, $remainingQuota - $sendingNow));
            $ids = MarketingOutbox::where('status', 'pending')->orderBy('id')->limit($batchSize)->pluck('id');
            MarketingOutbox::whereIn('id', $ids)->where('status', 'pending')->update(['status' => 'sending', 'updated_at' => now()]);
            $pending = MarketingOutbox::whereIn('id', $ids)->where('status', 'sending')->orderBy('id')->get();

            $sentCount = 0;

            foreach ($pending as $row) {
                $row->increment('attempts');

                try {
                    // notifyNow: the outbox IS the queue (cap + retries), so the mail must really go out here.
                    if ($row->kind === 'back_in_stock') {
                        Notification::route('mail', $row->email)
                            ->notifyNow((new BackInStockNotification($row->payload))->locale($row->locale));
                    } elseif ($row->kind === 'newsletter') {
                        $subscriber = NewsletterSubscriber::find($row->payload['subscriber_id'] ?? 0);
                        if ($subscriber === null || $subscriber->status !== 'confirmed') {
                            $row->update(['status' => 'cancelled', 'last_error' => 'Unsubscribed before sending.']);

                            continue;
                        }
                        Notification::route('mail', $subscriber->email)
                            ->notifyNow((new NewsletterCampaignNotification([...$row->payload, 'unsubscribe_token' => $subscriber->unsubscribe_token]))->locale($row->locale));
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

            $this->finishCampaigns();

            return $sentCount;
        }) ?? 0;
    }

    /** A campaign is `sent` once none of its outbox rows is waiting any more. */
    private function finishCampaigns(): void
    {
        NewsletterCampaign::where('status', 'sending')->get()->each(function (NewsletterCampaign $campaign) {
            $waiting = MarketingOutbox::where('kind', 'newsletter')->whereIn('status', ['pending', 'sending'])
                ->whereRaw("(payload->>'campaign_id')::bigint = ?", [$campaign->id])->exists();
            if (! $waiting) {
                $campaign->update(['status' => 'sent', 'sent_at' => now()]);
            }
        });
    }
}

<?php

namespace App\Services\Health;

use App\Services\Telegram\TelegramBot;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthService
{
    private const STUCK_QUEUE_SECONDS = 600;

    public function checkDatabase(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function checkQueue(): array
    {
        $queue = ['status' => 'ok', 'driver' => config('queue.default')];
        if (config('queue.default') === 'database') {
            $oldest = DB::table('jobs')->min('created_at');
            $queue += [
                'pending' => DB::table('jobs')->count(),
                'failed' => DB::table('failed_jobs')->count(),
                'oldest_pending_seconds' => $oldest ? max(0, now()->getTimestamp() - (int) $oldest) : null,
            ];
            if ($queue['oldest_pending_seconds'] !== null && $queue['oldest_pending_seconds'] > self::STUCK_QUEUE_SECONDS) {
                $queue['status'] = 'degraded'; // usually means no queue worker is running
            }
        }

        return $queue;
    }

    public function checkTelegram(): array
    {
        if (! TelegramBot::enabled()) {
            return ['status' => 'off'];
        }

        try {
            $bot = app(TelegramBot::class);
            $base = (string) config('app.url');
            $expected = $bot->webhookUrl($base);
            $token = (string) config('services.telegram_bot.token');
            $key = 'health-telegram-bot:'.hash('sha256', $token.'|'.$expected);
            $cached = Cache::remember($key, now()->addMinutes(10), function () use ($bot) {
                try {
                    return ['info' => $bot->call('getWebhookInfo')];
                } catch (Throwable) {
                    return ['error' => 'Telegram unreachable'];
                }
            });

            if (isset($cached['error'])) {
                return ['status' => 'degraded', 'error' => $cached['error']];
            }

            $info = $cached['info'];
            if (($info['url'] ?? '') !== $expected) {
                return ['status' => 'degraded', 'error' => 'webhook not set'] + $this->repairWebhook($bot, $base, $key);
            }
            if (filled($info['last_error_message'] ?? null) && (int) ($info['last_error_date'] ?? 0) > now()->subMinutes(30)->timestamp) {
                return ['status' => 'degraded', 'error' => str_replace($token, '***', (string) $info['last_error_message'])];
            }

            return ['status' => 'ok'];
        } catch (Throwable) {
            return ['status' => 'degraded', 'error' => 'Telegram unreachable'];
        }
    }

    public function publicCheck(): JsonResponse
    {
        if (! $this->checkDatabase()) {
            return response()->json(['status' => 'fail'], 503);
        }

        // Side work (Telegram webhook self-repair) runs in the background of health check
        $this->checkTelegram();

        return response()->json(['status' => 'ok']);
    }

    public function detailedCheck(): JsonResponse
    {
        if (! $this->checkDatabase()) {
            return response()->json([
                'status' => 'down',
                'checks' => [
                    'database' => ['status' => 'down'],
                ],
            ], 503);
        }

        $database = ['status' => 'ok'];
        $queue = $this->checkQueue();
        $telegram = $this->checkTelegram();

        $status = $queue['status'] === 'degraded' || $telegram['status'] === 'degraded' ? 'degraded' : 'ok';

        return response()->json([
            'status' => $status,
            'checks' => [
                'database' => $database,
                'queue' => $queue,
                'telegram_bot' => $telegram,
            ],
        ]);
    }

    private function repairWebhook(TelegramBot $bot, string $base, string $healthCacheKey): array
    {
        if (! Cache::add('health-telegram-webhook-repair', true, now()->addMinutes(10))) {
            return [];
        }

        try {
            $bot->ensureWebhook($base);
        } catch (Throwable $e) {
            report($e);

            return ['repaired' => false];
        }
        Cache::forget($healthCacheKey);

        return ['repaired' => true];
    }
}

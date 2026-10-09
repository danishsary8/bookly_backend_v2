<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramBot;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** For uptime monitors: 503 when the database is down, "degraded" for stuck jobs or a broken Telegram bot. */
class HealthController extends Controller
{
    private const STUCK_QUEUE_SECONDS = 600;

    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = ['status' => 'ok'];
        } catch (Throwable) {
            return response()->json(['status' => 'down', 'checks' => ['database' => ['status' => 'down']]], 503);
        }

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

        $telegram = $this->telegramHealth();
        $status = $queue['status'] === 'degraded' || $telegram['status'] === 'degraded' ? 'degraded' : 'ok';

        return response()->json(['status' => $status, 'checks' => ['database' => $database, 'queue' => $queue, 'telegram_bot' => $telegram]]);
    }

    private function telegramHealth(): array
    {
        if (! TelegramBot::enabled()) {
            return ['status' => 'off'];
        }

        try {
            $bot = app(TelegramBot::class);
            $base = app()->isProduction() ? 'https://'.request()->getHttpHost() : request()->getSchemeAndHttpHost();
            $expected = $bot->webhookUrl($base);
            $token = (string) config('services.telegram_bot.token');
            $key = 'health-telegram-bot:'.hash('sha256', $token.'|'.$expected);
            $cached = Cache::remember($key, now()->addMinutes(10), function () use ($bot) {
                try {
                    return ['info' => $bot->call('getWebhookInfo')];
                } catch (Throwable) {
                    // Cache failures too; never expose exceptions containing the request URL/token.
                    return ['error' => 'Telegram unreachable'];
                }
            });

            if (isset($cached['error'])) {
                return ['status' => 'degraded', 'error' => $cached['error']];
            }

            $info = $cached['info'];
            if (($info['url'] ?? '') !== $expected) {
                return ['status' => 'degraded', 'error' => 'webhook not set'];
            }
            if (filled($info['last_error_message'] ?? null) && (int) ($info['last_error_date'] ?? 0) > now()->subMinutes(30)->timestamp) {
                return ['status' => 'degraded', 'error' => str_replace($token, '***', (string) $info['last_error_message'])];
            }

            return ['status' => 'ok'];
        } catch (Throwable) {
            return ['status' => 'degraded', 'error' => 'Telegram unreachable'];
        }
    }
}

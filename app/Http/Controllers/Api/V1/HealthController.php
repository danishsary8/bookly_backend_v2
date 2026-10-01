<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/** For uptime monitors: 503 when the database is down, "degraded" when queued emails are not being sent. */
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

        $status = $queue['status'] === 'ok' ? 'ok' : 'degraded';

        return response()->json(['status' => $status, 'checks' => ['database' => $database, 'queue' => $queue]]);
    }
}

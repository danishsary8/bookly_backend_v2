<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request an ID, returns it as `X-Request-Id` and adds it to every log line,
 * so one request can be followed through the logs (and matched to an error report).
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id');
        // Reuse an ID from a proxy/load balancer only if it is safe to put in logs.
        $id = preg_match('/^[A-Za-z0-9._-]{8,100}$/', $incoming) ? $incoming : (string) Str::uuid();

        $request->attributes->set('request_id', $id);
        Log::shareContext(['request_id' => $id]);

        $started = microtime(true);
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        if (config('logging.log_requests')) {
            $user = $request->user();
            Log::info('http.request', [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'status' => $response->getStatusCode(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'user_type' => $user ? Str::snake(class_basename($user)) : null,
                'user_id' => $user?->getKey(),
                'ip' => $request->ip(),
            ]);
        }

        return $response;
    }
}

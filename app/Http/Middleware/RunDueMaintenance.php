<?php

namespace App\Http\Middleware;

use App\Services\Maintenance\DueMaintenance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets the housekeeping jobs run from web traffic, after the response has been prepared, so the visitor never waits
 * for them (see DueMaintenance for why and when).
 */
class RunDueMaintenance
{
    public function __construct(private DueMaintenance $maintenance) {}

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($request->isMethod('OPTIONS')) {
            return;
        }

        $this->maintenance->runIfEnabled();
    }
}

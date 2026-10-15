<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetAppLocaleFromHeader
{
    /**
     * Set the application locale based on the Accept-Language header.
     * Public and customer routes support 'km' and 'en' (default: 'en').
     * Staff routes (/staff/* or api/v1/staff/*) ALWAYS stay English.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isStaffRoute($request)) {
            app()->setLocale('en');

            return $next($request);
        }

        $header = strtolower((string) $request->header('Accept-Language', ''));
        if (str_starts_with($header, 'km')) {
            app()->setLocale('km');
        } else {
            app()->setLocale('en');
        }

        return $next($request);
    }

    private function isStaffRoute(Request $request): bool
    {
        return $request->is('api/v1/staff/*')
            || $request->is('api/v1/staff')
            || $request->is('staff/*')
            || $request->is('staff');
    }
}

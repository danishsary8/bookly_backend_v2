<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Health\HealthService;
use Illuminate\Http\JsonResponse;

/** Public health check: returns only {status: "ok"} (200) or {status: "fail"} (503). */
class HealthController extends Controller
{
    public function __invoke(HealthService $health): JsonResponse
    {
        return $health->publicCheck();
    }
}

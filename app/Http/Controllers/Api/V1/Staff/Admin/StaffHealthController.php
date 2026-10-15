<?php

namespace App\Http\Controllers\Api\V1\Staff\Admin;

use App\Http\Controllers\Controller;
use App\Services\Health\HealthService;
use Illuminate\Http\JsonResponse;

/** Admin-only health check: returns full status and checks (database, queue, telegram_bot). */
class StaffHealthController extends Controller
{
    public function show(HealthService $health): JsonResponse
    {
        return $health->detailedCheck();
    }
}

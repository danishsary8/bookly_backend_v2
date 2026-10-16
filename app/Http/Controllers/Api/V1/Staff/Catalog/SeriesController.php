<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\SeriesResource;
use App\Models\Series;
use App\Services\Admin\AuditLogger;
use App\Services\Admin\StrictDeleteService;
use App\Services\Telegram\OpsAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeriesController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StrictDeleteService $strictDelete,
        private readonly OpsAlerts $opsAlerts,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $series = Series::create($request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]));
        $this->audit->created($request->user(), $series);

        return (new SeriesResource($series))->response()->setStatusCode(201);
    }

    public function update(Request $request, Series $series): SeriesResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:5000'],
        ]);

        $before = $series->getAttributes();
        $series->update($data);
        $this->audit->updated($request->user(), $series, $before);

        return new SeriesResource($series);
    }

    public function destroy(Request $request, Series $series): JsonResponse
    {
        $inUse = $series->books()->withTrashed()->count();
        if ($inUse > 0) {
            return response()->json(['message' => "This series has {$inUse} book(s). Remove them from the series first."], 409);
        }

        $data = $request->validate([
            'confirm' => ['required', 'string'],
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();
        if ($failed = $this->strictDelete->verify($user, $data['confirm'], $series->name, $data['password'], $data['code'])) {
            return $failed;
        }

        $before = $series->getAttributes();
        $series->delete();
        $this->audit->custom($user, 'deleted', $series, $before, $series->fresh()?->getAttributes() ?? []);

        $this->opsAlerts->itemDeletedByAdmin($user->name, 'Series', $series->name);

        return response()->json(null, 204);
    }
}

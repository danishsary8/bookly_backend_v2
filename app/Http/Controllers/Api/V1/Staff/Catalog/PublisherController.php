<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublisherResource;
use App\Models\Publisher;
use App\Services\Admin\AuditLogger;
use App\Services\Admin\StrictDeleteService;
use App\Services\Telegram\OpsAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PublisherController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StrictDeleteService $strictDelete,
        private readonly OpsAlerts $opsAlerts,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $publisher = Publisher::create($request->validate([
            'name' => ['required', 'string', 'max:150', 'unique:publishers,name'],
        ]));
        $this->audit->created($request->user(), $publisher);

        return (new PublisherResource($publisher))->response()->setStatusCode(201);
    }

    public function update(Request $request, Publisher $publisher): PublisherResource
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('publishers', 'name')->ignore($publisher)],
        ]);

        $before = $publisher->getAttributes();
        $publisher->update($data);
        $this->audit->updated($request->user(), $publisher, $before);

        return new PublisherResource($publisher);
    }

    public function destroy(Request $request, Publisher $publisher): JsonResponse
    {
        $inUse = $publisher->books()->withTrashed()->count();
        if ($inUse > 0) {
            return response()->json(['message' => "This publisher has {$inUse} book(s). Reassign them first."], 409);
        }

        $data = $request->validate([
            'confirm' => ['required', 'string'],
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();
        if ($failed = $this->strictDelete->verify($user, $data['confirm'], $publisher->name, $data['password'], $data['code'])) {
            return $failed;
        }

        $before = $publisher->getAttributes();
        $publisher->delete();
        $this->audit->custom($user, 'deleted', $publisher, $before, $publisher->fresh()?->getAttributes() ?? []);

        $this->opsAlerts->itemDeletedByAdmin($user->name, 'Publisher', $publisher->name);

        return response()->json(null, 204);
    }
}

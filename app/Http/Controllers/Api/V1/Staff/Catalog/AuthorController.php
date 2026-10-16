<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthorResource;
use App\Models\Author;
use App\Services\Admin\AuditLogger;
use App\Services\Admin\StrictDeleteService;
use App\Services\Telegram\OpsAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthorController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly StrictDeleteService $strictDelete,
        private readonly OpsAlerts $opsAlerts,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $author = Author::create($this->validated($request));
        $this->audit->created($request->user(), $author);

        return (new AuthorResource($author))->response()->setStatusCode(201);
    }

    public function update(Request $request, Author $author): AuthorResource
    {
        $before = $author->getAttributes();
        $author->update($this->validated($request, partial: true));
        $this->audit->updated($request->user(), $author, $before);

        return new AuthorResource($author);
    }

    public function destroy(Request $request, Author $author): JsonResponse
    {
        $inUse = $author->books()->withTrashed()->count();
        if ($inUse > 0) {
            return response()->json(['message' => "This author is linked to {$inUse} book(s). Remove them from those books first."], 409);
        }

        $data = $request->validate([
            'confirm' => ['required', 'string'],
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        $user = $request->user();
        if ($failed = $this->strictDelete->verify($user, $data['confirm'], $author->name, $data['password'], $data['code'])) {
            return $failed;
        }

        $before = $author->getAttributes();
        $author->delete();
        $this->audit->custom($user, 'deleted', $author, $before, $author->fresh()?->getAttributes() ?? []);

        $this->opsAlerts->itemDeletedByAdmin($user->name, 'Author', $author->name);

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:150'],
            'bio' => ['nullable', 'string', 'max:5000'],
            'photo_url' => ['nullable', 'url:http,https', 'max:500'],
        ]);
    }
}

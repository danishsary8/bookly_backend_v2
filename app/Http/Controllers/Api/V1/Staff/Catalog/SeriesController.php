<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\SeriesResource;
use App\Models\Book;
use App\Models\Series;
use App\Services\Admin\AuditLogger;
use App\Services\Admin\StrictDeleteService;
use App\Services\Telegram\OpsAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

    public function show(Series $series): JsonResponse
    {
        $series->load(['books' => fn ($q) => $q
            ->with(['authors', 'activeVariants'])
            ->orderByRaw('series_order ASC NULLS LAST')
            ->orderBy('id'),
        ]);

        $books = $series->books->map(fn (Book $book) => [
            'id' => $book->id,
            'title' => $book->title,
            'status' => is_string($book->status) ? $book->status : ($book->status?->value ?? (string) $book->status),
            'series_order' => $book->series_order,
            'cover_image_url' => $book->activeVariants->firstWhere('cover_image_url', '!=', null)?->cover_image_url,
            'authors' => $book->authors->map(fn ($a) => ['id' => $a->id, 'name' => $a->name])->values(),
            'formats' => $book->activeVariants->map(fn ($v) => $v->format->value)->values(),
            'in_stock' => $book->activeVariants->contains(fn ($v) => $v->isAvailable()),
            'created_at' => $book->created_at?->toIso8601String(),
        ]);

        $duplicateOrders = $series->books()
            ->whereNotNull('series_order')
            ->select('series_order', DB::raw('count(*) as count'))
            ->groupBy('series_order')
            ->havingRaw('count(*) > 1')
            ->pluck('series_order')
            ->all();

        $warnings = array_map(fn ($num) => "Duplicate volume number {$num} assigned to multiple books.", $duplicateOrders);

        return response()->json([
            'data' => [
                'id' => $series->id,
                'name' => $series->name,
                'description' => $series->description,
                'books' => $books,
                'warnings' => $warnings,
            ],
        ]);
    }

    public function updateVolumes(Request $request, Series $series): JsonResponse
    {
        $data = $request->validate([
            'volumes' => ['required', 'array'],
            'volumes.*.id' => ['required', 'integer'],
            'volumes.*.series_order' => ['nullable', 'integer', 'min:1', 'max:32767'],
        ]);

        $bookIds = collect($data['volumes'])->pluck('id')->all();
        $books = $series->books()->whereIn('id', $bookIds)->get()->keyBy('id');

        $before = [];
        $after = [];

        DB::transaction(function () use ($data, $books, &$before, &$after) {
            foreach ($data['volumes'] as $vol) {
                $book = $books->get($vol['id']);
                if ($book) {
                    $before[$book->id] = $book->series_order;
                    $book->update(['series_order' => $vol['series_order']]);
                    $after[$book->id] = $vol['series_order'];
                }
            }
        });

        $this->audit->custom($request->user(), 'updated_volumes', $series, $before, $after);

        // Check for duplicates
        $duplicateOrders = $series->books()
            ->whereNotNull('series_order')
            ->select('series_order', DB::raw('count(*) as count'))
            ->groupBy('series_order')
            ->havingRaw('count(*) > 1')
            ->pluck('series_order')
            ->all();

        $warnings = array_map(fn ($num) => "Duplicate volume number {$num} assigned to multiple books.", $duplicateOrders);

        return response()->json([
            'data' => [
                'message' => 'Volumes updated successfully.',
                'warnings' => $warnings,
            ],
        ]);
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

<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Book;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\Series;
use App\Services\Admin\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class TrashController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Lists soft-deleted catalog items with a 30-day restore countdown.
     */
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));

        $models = $this->resolveTrashedQuery($type);

        $total = $models->count();
        $items = $models->slice(($page - 1) * $perPage, $perPage)->values();

        $paginator = new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return response()->json($paginator);
    }

    /**
     * Restores a soft-deleted catalog item from the trash.
     */
    public function restore(Request $request, string $type, int $id): JsonResponse
    {
        $modelClass = $this->resolveModelClass($type);
        if (! $modelClass) {
            return response()->json(['message' => 'Invalid catalog entity type.'], 404);
        }

        /** @var Model $model */
        $model = $modelClass::onlyTrashed()->findOrFail($id);
        $model->restore();

        $this->audit->custom($request->user(), 'restored', $model);

        $name = $model->title ?? $model->name ?? "Item #{$model->id}";

        return response()->json([
            'message' => "Restored {$name} successfully.",
            'item' => [
                'id' => $model->id,
                'type' => $this->normalizeType($type),
                'name' => $name,
            ],
        ]);
    }

    /**
     * @return Collection<int, array{id: int, type: string, name: string, deleted_at: string, days_until_purge: int}>
     */
    private function resolveTrashedQuery(?string $type): Collection
    {
        $entities = match ($type) {
            'books', 'book' => ['book' => Book::class],
            'authors', 'author' => ['author' => Author::class],
            'categories', 'category' => ['category' => Category::class],
            'publishers', 'publisher' => ['publisher' => Publisher::class],
            'series' => ['series' => Series::class],
            default => [
                'book' => Book::class,
                'author' => Author::class,
                'category' => Category::class,
                'publisher' => Publisher::class,
                'series' => Series::class,
            ],
        };

        $results = collect();

        foreach ($entities as $entityType => $class) {
            $records = $class::onlyTrashed()->latest('deleted_at')->get();
            foreach ($records as $record) {
                $deletedAt = $record->deleted_at;
                $daysUntilPurge = max(0, 30 - (int) $deletedAt->diffInDays(now()));

                $results->push([
                    'id' => $record->id,
                    'type' => $entityType,
                    'name' => $record->title ?? $record->name ?? "Item #{$record->id}",
                    'deleted_at' => $deletedAt->toISOString(),
                    'days_until_purge' => $daysUntilPurge,
                ]);
            }
        }

        return $results->sortByDesc('deleted_at')->values();
    }

    private function resolveModelClass(string $type): ?string
    {
        return match ($this->normalizeType($type)) {
            'book' => Book::class,
            'author' => Author::class,
            'category' => Category::class,
            'publisher' => Publisher::class,
            'series' => Series::class,
            default => null,
        };
    }

    private function normalizeType(string $type): string
    {
        return match (strtolower(trim($type))) {
            'books', 'book' => 'book',
            'authors', 'author' => 'author',
            'categories', 'category' => 'category',
            'publishers', 'publisher' => 'publisher',
            'series' => 'series',
            default => $type,
        };
    }
}

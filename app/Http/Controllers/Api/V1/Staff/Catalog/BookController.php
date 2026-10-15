<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffBookResource;
use App\Models\Book;
use App\Services\Admin\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookController extends Controller
{
    private const RELATIONS = ['authors', 'categories', 'variants'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** All books, including ones with no active variant yet. `trashed=1` lists deleted books. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', Rule::in(['draft', 'scheduled', 'live'])],
            'trashed' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return StaffBookResource::collection(
            Book::query()
                ->with(self::RELATIONS)
                ->when($request->boolean('trashed'), fn ($q) => $q->onlyTrashed())
                ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->when($data['q'] ?? null, fn ($q, $term) => $q->where('title', 'ilike', '%'.addcslashes($term, '%_').'%'))
                ->latest()
                ->orderByDesc('id')
                ->paginate($data['per_page'] ?? 20)
                ->withQueryString()
        );
    }

    public function show(int $book): StaffBookResource
    {
        return new StaffBookResource(Book::withTrashed()->with(self::RELATIONS)->findOrFail($book));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['language'] ??= 'English';

        $user = $request->user();
        $isAdmin = $user->role === StaffRole::Admin;

        if (! $isAdmin) {
            if ($request->has('status') && in_array($request->input('status'), ['live', 'scheduled'], true)) {
                abort(403, 'Only an admin can publish books.');
            }
            if ($request->filled('publish_at')) {
                abort(403, 'Only an admin can publish books.');
            }
            $data['status'] = 'draft';
            $data['publish_at'] = null;
        } else {
            $data['status'] = $data['status'] ?? 'live';
            if ($data['status'] === 'scheduled') {
                if (empty($data['publish_at'])) {
                    throw ValidationException::withMessages(['publish_at' => 'A publish date is required for scheduled books.']);
                }
                if (Carbon::parse($data['publish_at'])->isPast()) {
                    throw ValidationException::withMessages(['publish_at' => 'Publish date must be in the future.']);
                }
            } else {
                $data['publish_at'] = null;
            }
        }

        $book = DB::transaction(function () use ($data, $request) {
            $book = Book::create($data);
            $book->authors()->sync($data['author_ids'] ?? []);
            $book->categories()->sync($data['category_ids'] ?? []);
            $this->audit->custom($request->user(), 'created', $book, null, $this->snapshot($book));

            return $book;
        });

        return (new StaffBookResource($book->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(Request $request, Book $book): StaffBookResource
    {
        $data = $this->validated($request, partial: true);
        $before = $this->snapshot($book);

        $user = $request->user();
        $isAdmin = $user->role === StaffRole::Admin;

        if (! $isAdmin) {
            if ($request->has('status') && $request->input('status') !== $book->status && in_array($request->input('status'), ['live', 'scheduled'], true)) {
                abort(403, 'Only an admin can publish books.');
            }
            if ($request->has('publish_at') && $request->input('publish_at') != $book->publish_at) {
                abort(403, 'Only an admin can publish books.');
            }
            unset($data['status'], $data['publish_at']);
        } else {
            if (isset($data['status'])) {
                if ($data['status'] === 'scheduled') {
                    $publishAt = $data['publish_at'] ?? $book->publish_at;
                    if (empty($publishAt)) {
                        throw ValidationException::withMessages(['publish_at' => 'A publish date is required for scheduled books.']);
                    }
                    if (Carbon::parse($publishAt)->isPast()) {
                        throw ValidationException::withMessages(['publish_at' => 'Publish date must be in the future.']);
                    }
                } elseif ($data['status'] !== 'scheduled') {
                    $data['publish_at'] = null;
                }
            }
        }

        DB::transaction(function () use ($book, $data, $request, $before) {
            $book->update($data);
            if (array_key_exists('author_ids', $data)) {
                $book->authors()->sync($data['author_ids']);
            }
            if (array_key_exists('category_ids', $data)) {
                $book->categories()->sync($data['category_ids']);
            }

            $after = $this->snapshot($book->fresh());
            $changed = array_keys(array_filter($after, fn ($value, $key) => $before[$key] != $value, ARRAY_FILTER_USE_BOTH));
            if ($changed !== []) {
                $only = array_flip($changed);
                $this->audit->custom($request->user(), 'updated', $book, array_intersect_key($before, $only), array_intersect_key($after, $only));
            }
        });

        return new StaffBookResource($book->fresh()->load(self::RELATIONS));
    }

    /** Soft delete: the book disappears from the catalog but stays linked to past orders. */
    public function destroy(Request $request, Book $book): JsonResponse
    {
        $book->delete();
        $this->audit->deleted($request->user(), $book);

        return response()->json(null, 204);
    }

    public function restore(Request $request, int $book): StaffBookResource
    {
        $model = Book::onlyTrashed()->findOrFail($book);
        $model->restore();
        $this->audit->custom($request->user(), 'restored', $model);

        return new StaffBookResource($model->load(self::RELATIONS));
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:20000'],
            'status' => ['sometimes', 'string', Rule::in(['draft', 'scheduled', 'live'])],
            'publish_at' => ['nullable', 'date'],
            'publisher_id' => ['nullable', 'integer', 'exists:publishers,id'],
            'series_id' => ['nullable', 'integer', 'exists:series,id'],
            'series_order' => ['nullable', 'integer', 'min:1', 'max:32767'],
            'language' => $partial ? ['sometimes', 'required', 'string', 'max:50'] : ['nullable', 'string', 'max:50'],
            'page_count' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'publish_date' => ['nullable', 'date'],
            'author_ids' => ['sometimes', 'array'],
            'author_ids.*' => ['integer', 'distinct', 'exists:authors,id'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
        ]);
    }

    private function snapshot(Book $book): array
    {
        return [
            ...collect($book->only(['title', 'description', 'status', 'publisher_id', 'series_id', 'series_order', 'language', 'page_count']))->all(),
            'publish_at' => $book->publish_at?->toISOString(),
            'publish_date' => $book->publish_date?->toDateString(),
            'author_ids' => $book->authors()->orderBy('authors.id')->pluck('authors.id')->all(),
            'category_ids' => $book->categories()->orderBy('categories.id')->pluck('categories.id')->all(),
        ];
    }
}

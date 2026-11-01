<?php

namespace App\Http\Controllers\Api\V1\Staff\Promotions;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffCollectionResource;
use App\Models\Book;
use App\Models\BookCollection;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Seasonal collections (owner 2026-11-01): staff with catalog:view see them; only admins create, change or delete
 * them (same rule as offers). `books` is the full ordered list of book ids each time it is sent.
 */
class CollectionController extends Controller
{
    public const MAX_BOOKS = 60;

    /** Web addresses the shop already uses under /collections/. */
    private const RESERVED = ['live'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return StaffCollectionResource::collection(
            BookCollection::query()
                ->with(['books' => fn ($q) => $q->withTrashed()])
                ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                    ->where('title_en', 'ilike', '%'.addcslashes($term, '%_').'%')
                    ->orWhere('title_km', 'ilike', '%'.addcslashes($term, '%_').'%')))
                ->orderBy('position')->orderByDesc('id')
                ->paginate($data['per_page'] ?? 25)
                ->withQueryString()
        );
    }

    public function show(BookCollection $collection): StaffCollectionResource
    {
        return new StaffCollectionResource($collection->load(['books' => fn ($q) => $q->withTrashed()]));
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->filled('slug') && $request->filled('title_en')) {
            $request->merge(['slug' => Str::slug((string) $request->input('title_en'))]);
        }
        $data = $request->validate($this->rules($request));
        $this->checkDates($data['starts_at'] ?? null, $data['ends_at'] ?? null);
        $books = $this->books($data);

        $collection = DB::transaction(function () use ($data, $books) {
            $collection = BookCollection::create(collect($data)->except('books')->all());
            $this->saveBooks($collection, $books);

            return $collection;
        });
        $this->audit->custom($request->user(), 'created', $collection, null, [...$collection->getAttributes(), 'books' => $books]);

        return (new StaffCollectionResource($collection->refresh()->load(['books' => fn ($q) => $q->withTrashed()])))->response()->setStatusCode(201);
    }

    public function update(Request $request, BookCollection $collection): StaffCollectionResource
    {
        $data = $request->validate($this->rules($request, $collection));
        $this->checkDates(
            array_key_exists('starts_at', $data) ? $data['starts_at'] : $collection->starts_at,
            array_key_exists('ends_at', $data) ? $data['ends_at'] : $collection->ends_at,
        );
        $books = array_key_exists('books', $data) ? $this->books($data) : null;

        $before = [...$collection->getAttributes(), 'books' => $this->bookIds($collection)];
        DB::transaction(function () use ($collection, $data, $books) {
            $collection->update(collect($data)->except('books')->all());
            if ($books !== null) {
                $this->saveBooks($collection, $books);
            }
        });
        $after = [...$collection->getAttributes(), 'books' => $this->bookIds($collection)];
        $changed = array_keys(array_filter($after, fn ($v, $k) => $k !== 'updated_at' && ($before[$k] ?? null) != $v, ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            $pick = fn (array $row) => array_intersect_key($row, array_flip($changed));
            $this->audit->custom($request->user(), 'updated', $collection, $pick($before), $pick($after));
        }

        return new StaffCollectionResource($collection->refresh()->load(['books' => fn ($q) => $q->withTrashed()]));
    }

    public function destroy(Request $request, BookCollection $collection): JsonResponse
    {
        $before = [...$collection->getAttributes(), 'books' => $this->bookIds($collection)];
        $collection->delete(); // its book rows go with it (ON DELETE CASCADE)
        $this->audit->custom($request->user(), 'deleted', $collection, $before, null);

        return response()->json(null, 204);
    }

    private function rules(Request $request, ?BookCollection $collection = null): array
    {
        $required = $collection ? 'sometimes' : 'required';

        return [
            'slug' => [$required, 'string', 'max:120', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::notIn(self::RESERVED), Rule::unique('collections', 'slug')->ignore($collection)],
            'title_en' => [$required, 'string', 'min:1', 'max:120'],
            'title_km' => ['sometimes', 'nullable', 'string', 'max:120'],
            'description_en' => ['sometimes', 'nullable', 'string', 'max:300'],
            'description_km' => ['sometimes', 'nullable', 'string', 'max:300'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'books' => ['sometimes', 'array', 'max:'.self::MAX_BOOKS],
            'books.*' => ['integer', 'distinct', 'min:1'],
        ];
    }

    /** @return list<int> the ordered ids; every book must exist and not be in the trash */
    private function books(array $data): array
    {
        $ids = array_values(array_map('intval', $data['books'] ?? []));
        $found = Book::query()->whereKey($ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $missing = array_values(array_diff($ids, $found));
        if ($missing !== []) {
            throw ValidationException::withMessages(['books' => 'Not found (or in the trash): book '.implode(', book ', $missing).'.']);
        }

        return $ids;
    }

    private function saveBooks(BookCollection $collection, array $ids): void
    {
        DB::table('collection_books')->where('collection_id', $collection->id)->delete();
        DB::table('collection_books')->insert(array_map(
            fn ($id, $i) => ['collection_id' => $collection->id, 'book_id' => $id, 'position' => $i],
            $ids,
            array_keys($ids),
        ));
    }

    /** @return list<int> */
    private function bookIds(BookCollection $collection): array
    {
        return DB::table('collection_books')->where('collection_id', $collection->id)->orderBy('position')->pluck('book_id')->map(fn ($id) => (int) $id)->all();
    }

    private function checkDates(mixed $startsAt, mixed $endsAt): void
    {
        if ($startsAt !== null && $endsAt !== null && now()->parse($endsAt)->lte(now()->parse($startsAt))) {
            throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
        }
    }
}

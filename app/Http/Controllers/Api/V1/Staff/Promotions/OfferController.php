<?php

namespace App\Http\Controllers\Api\V1\Staff\Promotions;

use App\Http\Controllers\Controller;
use App\Http\Resources\OfferResource;
use App\Models\Book;
use App\Models\Category;
use App\Models\Offer;
use App\Models\Series;
use App\Services\Admin\AuditLogger;
use App\Services\Offers\OfferService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Automatic offers (docs/superpowers/specs/2026-10-31-offers-design.md 4.10 and 6.7). Staff with catalog:view see
 * them; only admins create, change, pause or delete them (an offer gives money away, same rule as coupons).
 * Every save clears the live-offers cache, so the shop sees the change at once.
 */
class OfferController extends Controller
{
    private const TARGET_TYPES = ['book' => Book::class, 'category' => Category::class, 'series' => Series::class];

    private const RELATIONS = ['targets.book', 'targets.category', 'targets.series'];

    public function __construct(private readonly AuditLogger $audit, private readonly OfferService $offers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Offer::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return OfferResource::collection(
            Offer::query()
                ->with(self::RELATIONS)
                ->withCount('orders')
                ->when($data['q'] ?? null, fn ($q, $term) => $q->where('name', 'ilike', '%'.addcslashes($term, '%_').'%'))
                ->when($data['status'] ?? null, fn (Builder $q, string $status) => $this->whereStatus($q, $status))
                ->latest()
                ->orderByDesc('id')
                ->paginate($data['per_page'] ?? 20)
                ->withQueryString()
        );
    }

    public function show(Offer $offer): OfferResource
    {
        return new OfferResource($offer->load(self::RELATIONS)->loadCount('orders'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules($request));
        if (($data['type'] ?? '') === 'gift') {
            $data['percent_off'] = '0.00';
            $data['min_quantity'] ??= 1;
        }
        if ($data['whole_series'] ?? false) {
            $data['min_quantity'] ??= 2; // not used by a set offer: the set is every volume of the series
        }
        $targets = $this->targets($data);
        $this->checkSet($data['whole_series'] ?? false, $data['applies_to'], $targets);
        $this->checkDates($data['starts_at'] ?? null, $data['ends_at'] ?? null);

        $offer = DB::transaction(function () use ($data, $targets) {
            $offer = Offer::create(collect($data)->except('targets')->all());
            $offer->targets()->createMany($targets);

            return $offer;
        });

        $this->audit->custom($request->user(), 'created', $offer, null, [...$offer->getAttributes(), 'targets' => $targets]);
        $this->offers->forget();

        return (new OfferResource($offer->refresh()->load(self::RELATIONS)->loadCount('orders')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Offer $offer): OfferResource
    {
        $data = $request->validate($this->rules($request, $offer));
        if (($data['type'] ?? $offer->type) === 'gift') {
            $data['percent_off'] = '0.00';
        }
        $appliesTo = $data['applies_to'] ?? $offer->applies_to;
        $this->checkDates(
            array_key_exists('starts_at', $data) ? $data['starts_at'] : $offer->starts_at,
            array_key_exists('ends_at', $data) ? $data['ends_at'] : $offer->ends_at,
        );

        // Targets: replaced when sent; dropped when the offer becomes "all books"; a "selected" offer needs some.
        $newTargets = null;
        if ($appliesTo === Offer::APPLIES_TO_ALL) {
            $newTargets = [];
        } elseif (array_key_exists('targets', $data)) {
            $newTargets = $this->targets($data);
        } elseif (! $offer->targets()->exists()) {
            throw ValidationException::withMessages(['targets' => 'Choose at least one book, category or series.']);
        }

        $this->checkSet(
            $data['whole_series'] ?? $offer->whole_series,
            $appliesTo,
            $newTargets ?? $offer->targets()->get()->map(fn ($target) => [$target->type().'_id' => $target->targetId()])->all(),
        );

        $before = [...$offer->getAttributes(), 'targets' => $this->targetRows($offer)];
        DB::transaction(function () use ($offer, $data, $newTargets) {
            $offer->update(collect($data)->except('targets')->all());
            if ($newTargets !== null) {
                $offer->targets()->delete();
                $offer->targets()->createMany($newTargets);
            }
        });

        $after = [...$offer->getAttributes(), 'targets' => $this->targetRows($offer)];
        $changed = array_keys(array_filter($after, fn ($value, $key) => ! in_array($key, ['updated_at'], true)
            && ($before[$key] ?? null) != $value, ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            $pick = fn (array $row) => array_intersect_key($row, array_flip($changed));
            $this->audit->custom($request->user(), 'updated', $offer, $pick($before), $pick($after));
        }
        $this->offers->forget();

        return new OfferResource($offer->refresh()->load(self::RELATIONS)->loadCount('orders'));
    }

    /** An offer that was used keeps the order history readable, so it can only be paused. */
    public function destroy(Request $request, Offer $offer): JsonResponse
    {
        if ($offer->used_count > 0 || $offer->orders()->exists()) {
            return response()->json(['message' => 'This offer has been used, so it cannot be deleted. Pause it instead (is_active: false).'], 409);
        }

        $before = [...$offer->getAttributes(), 'targets' => $this->targetRows($offer)];
        $offer->delete(); // its targets go with it (ON DELETE CASCADE)
        $this->audit->custom($request->user(), 'deleted', $offer, $before, null);
        $this->offers->forget();

        return response()->json(null, 204);
    }

    private function rules(Request $request, ?Offer $offer = null): array
    {
        $required = $offer ? 'sometimes' : 'required';
        $appliesTo = $request->input('applies_to', $offer?->applies_to);
        $gift = $request->input('type', $offer?->type) === 'gift';
        $set = $request->boolean('whole_series', (bool) $offer?->whole_series);

        return [
            'name' => [$required, 'string', 'min:1', 'max:100'],
            'type' => ['sometimes', Rule::in(['buy_n_percent', 'gift'])],
            'gift_id' => [Rule::requiredIf($gift && (! $offer?->gift_id || $request->exists('gift_id'))), 'nullable', 'integer', 'exists:gifts,id'],
            'gift_quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'min_subtotal' => ['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
            'applies_to' => [$required, Rule::in([Offer::APPLIES_TO_ALL, Offer::APPLIES_TO_SELECTED])],
            'whole_series' => ['sometimes', 'boolean', Rule::prohibitedIf($gift && $request->boolean('whole_series'))],
            'free_delivery' => ['sometimes', 'boolean'],
            'min_quantity' => [$gift || $set ? 'sometimes' : $required, 'integer', $gift ? 'min:1' : 'min:2', 'max:20'],
            'percent_off' => [$gift ? 'sometimes' : $required, 'numeric', $gift ? 'in:0' : 'min:0.01', 'max:50', 'decimal:0,2'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'max_orders' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
            'targets' => [
                Rule::requiredIf(! $offer && $appliesTo === Offer::APPLIES_TO_SELECTED),
                Rule::prohibitedIf($appliesTo === Offer::APPLIES_TO_ALL),
                'array', 'min:1', 'max:200',
            ],
            'targets.*.type' => ['required', Rule::in(array_keys(self::TARGET_TYPES))],
            'targets.*.id' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return list<array{book_id?: int, category_id?: int, series_id?: int}> unique rows; every id must exist (not in the trash) */
    private function targets(array $data): array
    {
        if (! isset($data['targets'])) {
            return [];
        }

        $rows = [];
        foreach ($data['targets'] as $target) {
            $rows[$target['type'].':'.$target['id']] = $target;
        }

        $missing = [];
        foreach (self::TARGET_TYPES as $type => $model) {
            $ids = collect($rows)->where('type', $type)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($ids === []) {
                continue;
            }
            $found = $model::query()->whereKey($ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
            foreach (array_diff($ids, $found) as $id) {
                $missing[] = "{$type} {$id}";
            }
        }
        if ($missing !== []) {
            throw ValidationException::withMessages(['targets' => 'Not found (or in the trash): '.implode(', ', $missing).'.']);
        }

        return collect($rows)->map(fn ($target) => [$target['type'].'_id' => (int) $target['id']])->values()->all();
    }

    /** @return list<string> e.g. ["series:3", "book:12"], for the audit log */
    private function targetRows(Offer $offer): array
    {
        return $offer->targets()->orderBy('id')->get()
            ->map(fn ($target) => $target->type().':'.$target->targetId())->all();
    }

    /** A set offer ("buy the whole series") only makes sense on chosen series. */
    private function checkSet(bool $wholeSeries, string $appliesTo, array $targets): void
    {
        if (! $wholeSeries) {
            return;
        }
        $onlySeries = $targets !== [] && collect($targets)->every(fn (array $target) => array_key_exists('series_id', $target));
        if ($appliesTo !== Offer::APPLIES_TO_SELECTED || ! $onlySeries) {
            throw ValidationException::withMessages(['whole_series' => 'A set offer must be for chosen series only (no single books or categories).']);
        }
    }

    private function checkDates(mixed $startsAt, mixed $endsAt): void
    {
        if ($startsAt !== null && $endsAt !== null && now()->parse($endsAt)->lte(now()->parse($startsAt))) {
            throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
        }
    }

    /** The same rules as Offer::status(), in SQL, for the list filter. */
    private function whereStatus(Builder $query, string $status): Builder
    {
        $notEnded = fn (Builder $q) => $q->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now()));
        $notUsedUp = fn (Builder $q) => $q->where(fn ($w) => $w->whereNull('max_orders')->orWhereColumn('used_count', '<', 'max_orders'));
        $hasBooks = fn (Builder $q) => $q->where(fn ($w) => $w
            ->where('applies_to', Offer::APPLIES_TO_ALL)
            ->orWhereHas('targets', fn ($t) => $t->where(fn ($u) => $u
                ->whereIn('book_id', Book::query()->select('id'))
                ->orWhereIn('category_id', Category::query()->select('id'))
                ->orWhereIn('series_id', Series::query()->select('id')))));
        $open = fn (Builder $q) => $notUsedUp($notEnded($q->where('is_active', true)));

        return match ($status) {
            'paused' => $query->where('is_active', false),
            'ended' => $query->where('is_active', true)->whereNotNull('ends_at')->where('ends_at', '<=', now()),
            'used_up' => $notEnded($query->where('is_active', true))->whereNotNull('max_orders')->whereColumn('used_count', '>=', 'max_orders'),
            'no_books' => $open($query)->whereNot(fn ($q) => $hasBooks($q)),
            'scheduled' => $hasBooks($open($query))->whereNotNull('starts_at')->where('starts_at', '>', now()),
            default => $hasBooks($open($query))->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now())),
        };
    }
}

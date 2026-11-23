<?php

namespace App\Http\Controllers\Api\V1\Staff\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AuditTimeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/** Read-only view of every change made by staff. */
class AuditLogController extends Controller
{
    public function index(Request $request, AuditTimeline $timeline): JsonResponse
    {
        $data = $request->validate([
            'staff_user_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:200'],
            'source' => ['nullable', Rule::in(['audit', 'inventory', 'orders', 'all'])],
            'actor' => ['nullable', Rule::in(['system', 'staff'])],
            'action' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:50'],
            'entity_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $source = $data['source'] ?? 'audit';
        $timezone = config('shop.timezone', 'Asia/Phnom_Penh');
        $base = $timeline->query($source);
        $page = (clone $base)
            ->leftJoin('staff_users as staff', 'staff.id', '=', 'events.staff_user_id')
            ->leftJoin('book_variants as variant', fn ($join) => $join->on('variant.id', '=', 'events.entity_id')->where('events.entity_type', 'book_variant'))
            ->leftJoin('books as book', 'book.id', '=', 'variant.book_id')
            ->select('events.*', 'staff.name as staff_name', 'staff.email as staff_email', 'variant.book_id as context_book_id', 'variant.sku as context_sku', 'book.title as context_title')
            ->when($data['staff_user_id'] ?? null, fn ($q, $id) => $q->where('staff_user_id', $id))
            ->when($data['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($data['entity_type'] ?? null, fn ($q, $type) => $q->where('entity_type', $type))
            ->when($data['entity_id'] ?? null, fn ($q, $id) => $q->where('entity_id', $id))
            ->when($data['actor'] ?? null, fn ($q, $actor) => $actor === 'system' ? $q->whereNull('staff_user_id') : $q->whereNotNull('staff_user_id'))
            ->when(trim($data['q'] ?? ''), function ($query, $term) {
                $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $query->where(function ($q) use ($pattern) {
                    foreach (['action', 'entity_type', 'staff.name', 'staff.email', 'variant.sku', 'book.title'] as $field) {
                        $q->orWhere($field, 'ilike', $pattern);
                    }
                    $q->orWhereRaw('events.entity_id::text ILIKE ?', [$pattern])
                        ->orWhereRaw('before_data::text ILIKE ?', [$pattern])
                        ->orWhereRaw('after_data::text ILIKE ?', [$pattern]);
                });
            })
            ->when($data['from'] ?? null, fn ($q, $from) => $q->where('events.created_at', '>=', Carbon::parse($from, $timezone)->startOfDay()->utc()))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->where('events.created_at', '<', Carbon::parse($to, $timezone)->addDay()->startOfDay()->utc()))
            ->orderByDesc('events.created_at')
            ->orderByDesc('events.source')
            ->orderByDesc('events.id')
            ->paginate($data['per_page'] ?? 50)
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn ($log) => [
                'id' => $log->id,
                'source' => $log->source,
                'context' => $log->context_book_id !== null ? ['book_id' => $log->context_book_id, 'book_title' => $log->context_title, 'sku' => $log->context_sku] : null,
                'staff' => $log->staff_name !== null ? ['id' => $log->staff_user_id, 'name' => $log->staff_name, 'email' => $log->staff_email] : null,
                'action' => $log->action,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'before' => $log->before_data !== null ? json_decode($log->before_data, true, flags: JSON_THROW_ON_ERROR) : null,
                'after' => $log->after_data !== null ? json_decode($log->after_data, true, flags: JSON_THROW_ON_ERROR) : null,
                'created_at' => Carbon::parse($log->created_at, 'UTC')->toISOString(),
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                // Distinct values for filter dropdowns (cached for 2 minutes per source to avoid 3 full union scans on every load).
                ...Cache::remember("audit-meta:{$source}", 120, fn () => [
                    'entity_types' => (clone $base)->distinct()->orderBy('entity_type')->pluck('entity_type'),
                    'actions' => (clone $base)->distinct()->orderBy('action')->pluck('action'),
                    'actors' => (clone $base)->join('staff_users as staff', 'staff.id', '=', 'events.staff_user_id')->select('staff.id', 'staff.name')->distinct()->orderBy('staff.name')->get(),
                ]),
                'timezone' => $timezone,
                'sources' => ['audit', 'inventory', 'orders'],
            ],
        ]);
    }
}

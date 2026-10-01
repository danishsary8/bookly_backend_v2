<?php

namespace App\Http\Controllers\Api\V1\Staff\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Read-only view of every change made by staff. */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'staff_user_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:50'],
            'entity_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', Rule::when($request->filled('from'), 'after_or_equal:from')],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = AdminAuditLog::query()
            ->with('staffUser')
            ->when($data['staff_user_id'] ?? null, fn ($q, $id) => $q->where('staff_user_id', $id))
            ->when($data['action'] ?? null, fn ($q, $action) => $q->where('action', $action))
            ->when($data['entity_type'] ?? null, fn ($q, $type) => $q->where('entity_type', $type))
            ->when($data['entity_id'] ?? null, fn ($q, $id) => $q->where('entity_id', $id))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<', Carbon::parse($to)->addDay()->startOfDay()))
            ->latest()
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 50)
            ->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (AdminAuditLog $log) => [
                'id' => $log->id,
                'staff' => $log->staffUser ? ['id' => $log->staffUser->id, 'name' => $log->staffUser->name, 'email' => $log->staffUser->email] : null,
                'action' => $log->action,
                'entity_type' => $log->entity_type,
                'entity_id' => $log->entity_id,
                'before' => $log->before_data,
                'after' => $log->after_data,
                'created_at' => $log->created_at,
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                // Distinct values for filter dropdowns.
                'entity_types' => AdminAuditLog::query()->distinct()->orderBy('entity_type')->pluck('entity_type'),
            ],
        ]);
    }
}

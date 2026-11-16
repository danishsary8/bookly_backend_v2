<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Enums\InventoryReason;
use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Services\Admin\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Suppliers (Phase 3). Staff who manage the catalogue add and edit them; a supplier is never deleted, it is switched
 * off (`is_active`) so restock history keeps pointing at it.
 */
class SupplierController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** All suppliers with what was bought from them (restocks, copies, money spent, last restock). */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'active' => ['nullable', Rule::in(['0', '1'])],
        ]);

        $rows = $this->query()
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('suppliers.name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('suppliers.contact_name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('suppliers.phone', 'ilike', '%'.addcslashes($term, '%_\\').'%')))
            ->when(isset($data['active']), fn ($q) => $q->where('suppliers.is_active', $data['active'] === '1'))
            ->orderByDesc('suppliers.is_active')->orderBy('suppliers.name')
            ->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->row($r))->all()]);
    }

    /** Suppliers joined with their restock totals. */
    private function query(): Builder
    {
        $stats = DB::table('inventory_movements')
            ->where('reason', InventoryReason::Restock->value)
            ->where('change_qty', '>', 0)
            ->whereNotNull('supplier_id')
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, COUNT(*) AS restocks, SUM(change_qty) AS units, COALESCE(SUM(change_qty * unit_cost_usd), 0) AS spend, MAX(created_at) AS last_restock_at');

        return DB::table('suppliers')
            ->leftJoinSub($stats, 's', 's.supplier_id', '=', 'suppliers.id')
            ->select(['suppliers.*', 's.restocks', 's.units', 's.spend', 's.last_restock_at']);
    }

    private function one(int $id): array
    {
        return $this->row($this->query()->where('suppliers.id', $id)->first());
    }

    public function store(Request $request): JsonResponse
    {
        $supplier = Supplier::create($this->validated($request));
        $this->audit->created($request->user(), $supplier);

        return response()->json(['data' => $this->one($supplier->id)], 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $data = $this->validated($request, $supplier);
        $before = $supplier->getAttributes();
        $supplier->update($data);
        $this->audit->updated($request->user(), $supplier, $before);

        return response()->json(['data' => $this->one($supplier->id)]);
    }

    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $data = $request->validate([
            'name' => [$supplier ? 'sometimes' : 'required', 'string', 'max:150'],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:190'],
            'note' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
            $taken = Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])
                ->when($supplier, fn ($q) => $q->whereKeyNot($supplier->getKey()))->exists();
            abort_if($taken, response()->json(['message' => 'A supplier with this name already exists.', 'errors' => ['name' => ['A supplier with this name already exists.']]], 422));
        }

        return $data;
    }

    private function row(object $r): array
    {
        $spend = Money::toCents($r->spend ?? 0);

        return [
            'id' => (int) $r->id,
            'name' => $r->name,
            'contact_name' => $r->contact_name,
            'phone' => $r->phone,
            'email' => $r->email,
            'note' => $r->note,
            'is_active' => (bool) $r->is_active,
            'restocks' => (int) ($r->restocks ?? 0),
            'units' => (int) ($r->units ?? 0),
            'spend_usd' => Money::format($spend),
            'last_restock_at' => $r->last_restock_at ?? null ? CarbonImmutable::parse($r->last_restock_at)->toIso8601ZuluString() : null,
        ];
    }
}

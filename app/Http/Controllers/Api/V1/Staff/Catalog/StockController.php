<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Enums\BookFormat;
use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryMovementResource;
use App\Http\Resources\StaffBookVariantResource;
use App\Http\Resources\StaffStockResource;
use App\Models\BookVariant;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Services\Admin\AuditLogger;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Lists physical variants with current stock, threshold, and status.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:low,out'],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = BookVariant::with('book')
            ->whereHas('book') // formats of deleted books are not listed
            ->whereIn('format', [BookFormat::Hardcover->value, BookFormat::Paperback->value]);

        if ($status = $data['status'] ?? null) {
            if ($status === 'low') {
                $query->where('stock_quantity', '>', 0)
                    ->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
            } elseif ($status === 'out') {
                $query->where('stock_quantity', '<=', 0);
            }
        }

        if (filled($data['q'] ?? null)) {
            $term = trim($data['q']);
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
            $query->where(function ($w) use ($escaped) {
                $w->whereHas('book', fn ($b) => $b->where('title', 'ILIKE', "%{$escaped}%"))
                    ->orWhere('sku', 'ILIKE', "%{$escaped}%")
                    ->orWhere('isbn', 'ILIKE', "%{$escaped}%");
            });
        }

        $variants = $query->orderBy('stock_quantity', 'asc')
            ->orderBy('id', 'asc')
            ->paginate($data['per_page'] ?? 20)
            ->withQueryString();

        return StaffStockResource::collection($variants);
    }

    /**
     * Restocks a physical variant.
     */
    public function restock(Request $request, BookVariant $variant): JsonResponse
    {
        if ($variant->format->isDigital()) {
            return response()->json([
                'message' => 'Digital formats have no stock.',
            ], 422);
        }

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'supplier' => ['nullable', 'string', 'max:150'],
            'unit_cost_usd' => ['nullable', 'numeric', 'min:0', 'max:99999.99', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        // The stock change and its audit entry commit together.
        $movement = DB::transaction(function () use ($variant, $data, $request) {
            $beforeStock = $variant->stock_quantity;
            $movement = $this->inventory->restock(
                variant: $variant,
                quantity: (int) $data['quantity'],
                staff: $request->user(),
                supplier: $data['supplier'] ?? null,
                unitCostUsd: $data['unit_cost_usd'] ?? null,
                note: $data['note'] ?? null,
            );
            $variant->refresh();
            $this->audit->custom(
                staff: $request->user(),
                verb: 'restocked',
                model: $variant,
                before: ['stock_quantity' => $beforeStock],
                after: [
                    'stock_quantity' => $variant->stock_quantity,
                    'restock_quantity' => $data['quantity'],
                    'supplier' => $data['supplier'] ?? null,
                    'unit_cost_usd' => $data['unit_cost_usd'] ?? null,
                    'note' => $data['note'] ?? null,
                ],
            );

            return $movement;
        });

        return response()->json([
            'variant' => new StaffBookVariantResource($variant),
            'movement' => new InventoryMovementResource($movement),
        ]);
    }

    /**
     * Stock movement history for a variant, newest first.
     */
    public function history(Request $request, BookVariant $variant): AnonymousResourceCollection
    {
        $movements = InventoryMovement::with(['createdBy:id,name'])
            ->where('book_variant_id', $variant->id)
            ->select('inventory_movements.*')
            ->selectRaw(
                '(? - COALESCE(SUM(change_qty) OVER (ORDER BY created_at DESC, id DESC ROWS BETWEEN UNBOUNDED PRECEDING AND 1 PRECEDING), 0)) as stock_after',
                [$variant->stock_quantity]
            )
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 20))));

        $orderIds = collect($movements->items())
            ->where('reference_type', 'order')
            ->pluck('reference_id')
            ->filter()
            ->unique();

        $orders = $orderIds->isNotEmpty()
            ? Order::whereIn('id', $orderIds)->pluck('order_number', 'id')
            : collect();

        foreach ($movements->items() as $item) {
            if ($item->reference_type === 'order' && isset($orders[$item->reference_id])) {
                $item->order_number = $orders[$item->reference_id];
            }
        }

        return InventoryMovementResource::collection($movements);
    }
}

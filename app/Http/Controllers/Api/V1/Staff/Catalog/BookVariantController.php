<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Enums\BookFormat;
use App\Enums\InventoryReason;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffBookVariantResource;
use App\Models\Book;
use App\Models\BookVariant;
use App\Services\Admin\AuditLogger;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BookVariantController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InventoryService $inventory,
    ) {}

    public function store(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            ...$this->rules(),
            'format' => ['required', Rule::enum(BookFormat::class), Rule::unique('book_variants')->where('book_id', $book->id)],
            'sku' => ['required', 'string', 'max:50', 'unique:book_variants,sku'],
            'isbn' => ['nullable', 'string', 'max:20', 'unique:book_variants,isbn'],
            'price_usd' => ['required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ]);
        $stock = (int) ($data['stock_quantity'] ?? 0);
        unset($data['stock_quantity']);

        $variant = DB::transaction(function () use ($book, $data, $stock, $request) {
            $variant = $book->variants()->create([...$data, 'stock_quantity' => 0]);
            $this->inventory->setStock($variant, $stock, InventoryReason::Restock, $request->user());
            $this->audit->created($request->user(), $variant->refresh());

            return $variant;
        });

        return (new StaffBookVariantResource($variant))->response()->setStatusCode(201);
    }

    public function update(Request $request, BookVariant $variant): StaffBookVariantResource
    {
        $data = $request->validate([
            ...$this->rules(),
            'format' => ['sometimes', 'required', Rule::enum(BookFormat::class), Rule::unique('book_variants')->where('book_id', $variant->book_id)->ignore($variant)],
            'sku' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('book_variants', 'sku')->ignore($variant)],
            'isbn' => ['nullable', 'string', 'max:20', Rule::unique('book_variants', 'isbn')->ignore($variant)],
            'price_usd' => ['sometimes', 'required', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ]);

        DB::transaction(function () use ($variant, $data, $request) {
            $before = $variant->getAttributes();

            if (array_key_exists('stock_quantity', $data)) {
                $this->inventory->setStock($variant, (int) $data['stock_quantity'], InventoryReason::Adjustment, $request->user());
                unset($data['stock_quantity']);
            }
            $variant->update($data);

            $after = $variant->refresh()->getAttributes();
            $changed = array_keys(array_diff_assoc(array_diff_key($after, ['updated_at' => 1]), $before));
            if ($changed !== []) {
                $only = array_flip($changed);
                $this->audit->custom($request->user(), 'updated', $variant, array_intersect_key($before, $only), array_intersect_key($after, $only));
            }
        });

        return new StaffBookVariantResource($variant);
    }

    /** Only variants that were never ordered can be deleted; ordered ones must be deactivated. */
    public function destroy(Request $request, BookVariant $variant): JsonResponse
    {
        if ($variant->orderItems()->exists()) {
            return response()->json([
                'message' => 'This format has been ordered before, so it cannot be deleted. Set is_active to false to hide it instead.',
            ], 409);
        }

        DB::transaction(function () use ($variant, $request) {
            // Never-ordered variant: its stock history goes with it; the audit log keeps the snapshot.
            $variant->inventoryMovements()->delete();
            $variant->delete();
            $this->audit->deleted($request->user(), $variant);
        });

        return response()->json(null, 204);
    }

    private function rules(): array
    {
        return [
            'stock_quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'cover_image_url' => ['nullable', 'url', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

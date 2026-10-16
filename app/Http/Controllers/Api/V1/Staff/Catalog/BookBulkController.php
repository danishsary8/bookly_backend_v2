<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Enums\InventoryReason;
use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookVariant;
use App\Services\Admin\AuditLogger;
use App\Services\Inventory\InventoryService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookBulkController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InventoryService $inventory,
    ) {}

    public function bulkBooks(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', 'exists:books,id'],
            'add_category_ids' => ['nullable', 'array'],
            'add_category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
            'remove_category_ids' => ['nullable', 'array'],
            'remove_category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
            'status' => ['nullable', 'string', Rule::in(['draft', 'scheduled', 'live'])],
            'publish_at' => ['nullable', 'date'],
        ]);

        $user = $request->user();
        $isAdmin = $user->role === StaffRole::Admin;

        if (array_key_exists('status', $data) || array_key_exists('publish_at', $data)) {
            if (! $isAdmin) {
                abort(403, 'Only an admin can publish books.');
            }

            if (($data['status'] ?? null) === 'scheduled') {
                if (empty($data['publish_at'])) {
                    throw ValidationException::withMessages(['publish_at' => 'A publish date is required for scheduled books.']);
                }
                if (Carbon::parse($data['publish_at'])->isPast()) {
                    throw ValidationException::withMessages(['publish_at' => 'Publish date must be in the future.']);
                }
            }
        }

        $items = DB::transaction(function () use ($data, $user) {
            $books = Book::whereIn('id', $data['ids'])->lockForUpdate()->get();
            if ($books->count() !== count($data['ids'])) {
                throw ValidationException::withMessages(['ids' => 'Some of these books were deleted. Reload the list and try again.']);
            }
            $results = [];

            foreach ($books as $book) {
                $before = $this->snapshotBook($book);

                if (! empty($data['add_category_ids'])) {
                    $book->categories()->syncWithoutDetaching($data['add_category_ids']);
                }
                if (! empty($data['remove_category_ids'])) {
                    $book->categories()->detach($data['remove_category_ids']);
                }
                if (array_key_exists('status', $data) && $data['status'] !== null) {
                    $book->status = $data['status'];
                    $book->publish_at = $data['status'] === 'scheduled' ? $data['publish_at'] : null;
                    $book->save();
                }

                $after = $this->snapshotBook($book->fresh());
                $changed = array_keys(array_filter($after, fn ($value, $key) => $before[$key] != $value, ARRAY_FILTER_USE_BOTH));
                if ($changed !== []) {
                    $only = array_flip($changed);
                    $this->audit->custom($user, 'bulk_updated', $book, array_intersect_key($before, $only), array_intersect_key($after, $only));
                }

                $results[] = [
                    'id' => $book->id,
                    'title' => $book->title,
                    'status' => $book->status,
                    'success' => true,
                ];
            }

            return $results;
        });

        return response()->json([
            'data' => [
                'updated_count' => count($items),
                'items' => $items,
            ],
        ]);
    }

    public function bulkVariants(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'distinct', 'exists:book_variants,id'],
            'stock_quantity' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'is_active' => ['nullable', 'boolean'],
            'price_usd' => ['nullable', 'numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
        ]);

        $user = $request->user();
        $isAdmin = $user->role === StaffRole::Admin;

        if (array_key_exists('price_usd', $data) && $data['price_usd'] !== null && ! $isAdmin) {
            abort(403, 'Only an admin can change book prices.');
        }

        $items = DB::transaction(function () use ($data, $user) {
            $variants = BookVariant::whereIn('id', $data['ids'])->whereHas('book')->lockForUpdate()->get();
            if ($variants->count() !== count($data['ids'])) {
                throw ValidationException::withMessages(['ids' => 'Some of these formats belong to deleted books. Reload the list and try again.']);
            }
            $results = [];

            foreach ($variants as $variant) {
                $before = $variant->only(['stock_quantity', 'low_stock_threshold', 'is_active', 'price_usd']);

                if (array_key_exists('stock_quantity', $data) && $data['stock_quantity'] !== null && ! $variant->format->isDigital()) {
                    $this->inventory->setStock($variant, (int) $data['stock_quantity'], InventoryReason::Adjustment, $user);
                    $variant->refresh(); // setStock writes the row itself; keep the model (and the result) current
                }

                if (array_key_exists('price_usd', $data) && $data['price_usd'] !== null) {
                    $variant->price_usd = $data['price_usd'];
                }
                if (array_key_exists('low_stock_threshold', $data) && $data['low_stock_threshold'] !== null) {
                    $variant->low_stock_threshold = $data['low_stock_threshold'];
                }
                if (array_key_exists('is_active', $data) && $data['is_active'] !== null) {
                    $variant->is_active = (bool) $data['is_active'];
                }

                $variant->save();

                $after = $variant->fresh()->only(['stock_quantity', 'low_stock_threshold', 'is_active', 'price_usd']);
                $changed = array_keys(array_filter($after, fn ($value, $key) => $before[$key] != $value, ARRAY_FILTER_USE_BOTH));
                if ($changed !== []) {
                    $only = array_flip($changed);
                    $this->audit->custom($user, 'bulk_updated', $variant, array_intersect_key($before, $only), array_intersect_key($after, $only));
                }

                $results[] = [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'stock_quantity' => $variant->stock_quantity,
                    'price_usd' => $variant->price_usd,
                    'is_active' => $variant->is_active,
                    'success' => true,
                ];
            }

            return $results;
        });

        return response()->json([
            'data' => [
                'updated_count' => count($items),
                'items' => $items,
            ],
        ]);
    }

    private function snapshotBook(Book $book): array
    {
        return [
            ...collect($book->only(['title', 'status', 'publisher_id', 'series_id', 'series_order', 'language', 'page_count']))->all(),
            'publish_at' => $book->publish_at?->toISOString(),
            'category_ids' => $book->categories()->orderBy('categories.id')->pluck('categories.id')->all(),
        ];
    }
}

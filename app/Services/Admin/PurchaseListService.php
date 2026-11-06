<?php

namespace App\Services\Admin;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use Illuminate\Support\Facades\DB;

class PurchaseListService
{
    /** Stock is already net of checkout reservations; waiting demand must not be added a second time. */
    public function items(): array
    {
        $waiting = DB::table('order_items as items')
            ->join('orders', 'orders.id', '=', 'items.order_id')
            ->whereNull('orders.deleted_at')
            ->where('orders.is_waiting_stock', true)
            ->whereIn('orders.status', [OrderStatus::Pending->value, OrderStatus::Paid->value, OrderStatus::Processing->value])
            ->groupBy('items.book_variant_id')
            ->selectRaw('items.book_variant_id, SUM(items.quantity) AS waiting_quantity');

        return DB::table('book_variants as variants')
            ->join('books', 'books.id', '=', 'variants.book_id')
            ->leftJoinSub($waiting, 'waiting', 'waiting.book_variant_id', '=', 'variants.id')
            ->whereNull('books.deleted_at')
            ->where('variants.is_active', true)
            ->whereIn('variants.format', [BookFormat::Paperback->value, BookFormat::Hardcover->value])
            ->where(fn ($query) => $query->whereColumn('variants.stock_quantity', '<=', 'variants.low_stock_threshold')
                ->orWhereNotNull('waiting.book_variant_id'))
            ->orderBy('books.title')->orderBy('variants.id')
            ->get(['books.id as book_id', 'books.title', 'variants.id as variant_id', 'variants.format', 'variants.sku',
                'variants.stock_quantity', 'variants.low_stock_threshold', 'waiting.waiting_quantity'])
            ->map(fn ($row) => [
                'book_id' => $row->book_id,
                'title' => $row->title,
                'variant_id' => $row->variant_id,
                'format' => $row->format,
                'sku' => $row->sku,
                'stock_quantity' => (int) $row->stock_quantity,
                'low_stock_threshold' => (int) $row->low_stock_threshold,
                'waiting_quantity' => (int) ($row->waiting_quantity ?? 0),
                'quantity_to_buy' => max(0, (int) $row->low_stock_threshold + 1 - (int) $row->stock_quantity),
            ])->all();
    }
}

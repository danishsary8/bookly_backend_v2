<?php

namespace App\Services\Inventory;

use App\Enums\InventoryReason;
use App\Models\BookVariant;
use App\Models\InventoryMovement;
use App\Models\MarketingOutbox;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\StaffUser;
use App\Models\StockAlert;
use Illuminate\Support\Facades\DB;

/** The only place stock changes, so every change has a matching inventory_movements row. */
class InventoryService
{
    /** Sets stock to an absolute number (staff edits) and logs the difference. */
    public function setStock(BookVariant $variant, int $newQuantity, InventoryReason $reason, ?StaffUser $staff = null): void
    {
        DB::transaction(function () use ($variant, $newQuantity, $reason, $staff) {
            $locked = BookVariant::whereKey($variant->getKey())->lockForUpdate()->firstOrFail();
            $delta = $newQuantity - $locked->stock_quantity;

            if ($delta === 0) {
                return;
            }

            $before = $locked->stock_quantity;
            $locked->update(['stock_quantity' => $newQuantity]);
            $this->log($locked, $delta, $reason, $staff);
            if ($before <= 0 && $newQuantity > 0) {
                $this->onStockRestored($locked);
            }
            $variant->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Takes sold copies out of stock. The caller must already hold a row lock on the variant
     * (checkout locks all variants in the cart). Returns true when this sale pushed stock to or
     * below the low-stock threshold, so the caller can alert staff once, not on every sale.
     */
    public function deductForSale(BookVariant $variant, int $quantity, Order $order): bool
    {
        $before = $variant->stock_quantity;
        $variant->update(['stock_quantity' => $before - $quantity]);
        $this->log($variant, -$quantity, InventoryReason::Sale, null, 'order', $order->id);

        return $before > $variant->low_stock_threshold && $variant->stock_quantity <= $variant->low_stock_threshold;
    }

    /** Restocks a variant with supplier, unit cost, and note. */
    public function restock(
        BookVariant $variant,
        int $quantity,
        ?StaffUser $staff = null,
        ?string $supplier = null,
        int|float|string|null $unitCostUsd = null,
        ?string $note = null,
    ): InventoryMovement {
        return DB::transaction(function () use ($variant, $quantity, $staff, $supplier, $unitCostUsd, $note) {
            $locked = BookVariant::whereKey($variant->getKey())->lockForUpdate()->firstOrFail();
            $before = $locked->stock_quantity;
            $newQuantity = $before + $quantity;
            $locked->update(['stock_quantity' => $newQuantity]);

            $movement = InventoryMovement::create([
                'book_variant_id' => $locked->id,
                'change_qty' => $quantity,
                'reason' => InventoryReason::Restock,
                'reference_type' => null,
                'reference_id' => null,
                'created_by_staff_id' => $staff?->id,
                'supplier' => $supplier,
                'unit_cost_usd' => $unitCostUsd,
                'note' => $note,
            ]);

            if ($before <= 0 && $newQuantity > 0) {
                $this->onStockRestored($locked);
            }

            $variant->setRawAttributes($locked->getAttributes(), true);

            return $movement;
        });
    }

    /** Puts the copies of a cancelled order back into stock. */
    public function restoreForCancellation(int $variantId, int $quantity, Order $order, ?StaffUser $staff = null): void
    {
        $variant = BookVariant::whereKey($variantId)->lockForUpdate()->firstOrFail();
        $before = $variant->stock_quantity;
        $variant->update(['stock_quantity' => $before + $quantity]);
        $this->log($variant, $quantity, InventoryReason::Adjustment, $staff, 'order', $order->id);
        if ($before <= 0 && $variant->stock_quantity > 0) {
            $this->onStockRestored($variant);
        }
    }

    /** Puts returned copies back into stock when a return is refunded. */
    public function restockFromReturn(int $variantId, int $quantity, OrderReturn $return, StaffUser $staff): void
    {
        $variant = BookVariant::whereKey($variantId)->lockForUpdate()->firstOrFail();
        $before = $variant->stock_quantity;
        $variant->update(['stock_quantity' => $before + $quantity]);
        $this->log($variant, $quantity, InventoryReason::ReturnIn, $staff, 'return', $return->id);
        if ($before <= 0 && $variant->stock_quantity > 0) {
            $this->onStockRestored($variant);
        }
    }

    /** Hook fired whenever a variant goes from 0 (or below) to above 0. Task 6 hooks back-in-stock notifications here. */
    protected function onStockRestored(BookVariant $variant): void
    {
        $alerts = StockAlert::where('book_variant_id', $variant->id)
            ->whereNull('notified_at')
            ->with('customer')
            ->get();

        if ($alerts->isEmpty()) {
            return;
        }

        $book = $variant->relationLoaded('book') ? $variant->book : $variant->book()->first();
        $now = now();

        foreach ($alerts as $alert) {
            $customer = $alert->customer;
            if ($customer && ! blank($customer->email)) {
                MarketingOutbox::create([
                    'kind' => 'back_in_stock',
                    'customer_id' => $customer->id,
                    'email' => $customer->email,
                    'locale' => $customer->locale ?? 'en',
                    'payload' => [
                        'book_id' => $book?->id ?? $variant->book_id,
                        'book_title' => $book?->title ?? 'Book',
                        'book_slug' => $book?->slug ?? '',
                        'variant_id' => $variant->id,
                        'format' => $variant->format instanceof \BackedEnum ? $variant->format->value : (string) $variant->format,
                        'customer_name' => $customer->name,
                    ],
                    'status' => 'pending',
                ]);
            }

            $alert->update(['notified_at' => $now]);
        }
    }

    private function log(BookVariant $variant, int $delta, InventoryReason $reason, ?StaffUser $staff, ?string $refType = null, ?int $refId = null): void
    {
        InventoryMovement::create([
            'book_variant_id' => $variant->id,
            'change_qty' => $delta,
            'reason' => $reason,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'created_by_staff_id' => $staff?->id,
        ]);
    }
}

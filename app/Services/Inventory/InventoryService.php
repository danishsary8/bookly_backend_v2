<?php

namespace App\Services\Inventory;

use App\Enums\InventoryReason;
use App\Models\BookVariant;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\StaffUser;
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

            $locked->update(['stock_quantity' => $newQuantity]);
            $this->log($locked, $delta, $reason, $staff);
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

    /** Puts the copies of a cancelled order back into stock. */
    public function restoreForCancellation(int $variantId, int $quantity, Order $order, ?StaffUser $staff = null): void
    {
        $variant = BookVariant::whereKey($variantId)->lockForUpdate()->firstOrFail();
        $variant->update(['stock_quantity' => $variant->stock_quantity + $quantity]);
        $this->log($variant, $quantity, InventoryReason::Adjustment, $staff, 'order', $order->id);
    }

    /** Puts returned copies back into stock when a return is refunded. */
    public function restockFromReturn(int $variantId, int $quantity, OrderReturn $return, StaffUser $staff): void
    {
        $variant = BookVariant::whereKey($variantId)->lockForUpdate()->firstOrFail();
        $variant->update(['stock_quantity' => $variant->stock_quantity + $quantity]);
        $this->log($variant, $quantity, InventoryReason::ReturnIn, $staff, 'return', $return->id);
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

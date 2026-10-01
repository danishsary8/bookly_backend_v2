<?php

namespace App\Services\Inventory;

use App\Enums\InventoryReason;
use App\Models\BookVariant;
use App\Models\InventoryMovement;
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

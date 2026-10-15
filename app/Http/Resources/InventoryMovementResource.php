<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isAdmin = $request->user()?->isAdmin() ?? false;

        $reference = null;
        if ($this->reference_type === 'order' && $this->reference_id) {
            $reference = [
                'type' => 'order',
                'id' => (int) $this->reference_id,
                'order_number' => $this->order_number ?? ($this->order?->order_number ?? null),
            ];
        } elseif ($this->reference_type === 'return' && $this->reference_id) {
            $reference = [
                'type' => 'return',
                'id' => (int) $this->reference_id,
            ];
        } elseif ($this->reference_type) {
            $reference = [
                'type' => $this->reference_type,
                'id' => (int) $this->reference_id,
            ];
        }

        $data = [
            'id' => $this->id,
            'book_variant_id' => $this->book_variant_id,
            'change_qty' => $this->change_qty,
            'reason' => $this->reason instanceof \BackedEnum ? $this->reason->value : (string) $this->reason,
            'reference' => $reference,
            'staff' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null,
            'note' => $this->note,
            'supplier' => $this->supplier,
            'stock_after' => isset($this->stock_after) ? (int) $this->stock_after : null,
            'created_at' => $this->created_at,
        ];

        if ($isAdmin) {
            $data['unit_cost_usd'] = $this->unit_cost_usd !== null ? (string) $this->unit_cost_usd : null;
        }

        return $data;
    }
}

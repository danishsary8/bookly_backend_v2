<?php

namespace App\Models;

use App\Enums\InventoryReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['book_variant_id', 'change_qty', 'reason', 'reference_type', 'reference_id', 'created_by_staff_id'];

    protected function casts(): array
    {
        return ['reason' => InventoryReason::class, 'change_qty' => 'integer'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(BookVariant::class, 'book_variant_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'created_by_staff_id');
    }
}

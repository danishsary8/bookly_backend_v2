<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAuditLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['staff_user_id', 'action', 'entity_type', 'entity_id', 'before_data', 'after_data'];

    protected function casts(): array
    {
        return ['before_data' => 'array', 'after_data' => 'array'];
    }

    public function staffUser(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class);
    }
}

<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends Model
{
    const UPDATED_AT = null;

    protected $table = 'order_status_history';

    protected $fillable = ['status', 'note', 'changed_by_staff_id'];

    protected function casts(): array
    {
        return ['status' => OrderStatus::class];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(StaffUser::class, 'changed_by_staff_id');
    }
}

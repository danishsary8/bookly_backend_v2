<?php

namespace App\Models;

use App\Enums\ReturnStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** "Return" is a reserved word in PHP, so the model for the `returns` table is OrderReturn. */
class OrderReturn extends Model
{
    protected $table = 'returns';

    protected $fillable = ['order_id', 'customer_id', 'reason', 'status', 'requested_at', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'status' => ReturnStatus::class,
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReturnItem::class, 'return_id');
    }
}

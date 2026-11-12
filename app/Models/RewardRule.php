<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What earns a "next order" reward coupon and what it gives (App\Services\Rewards\RewardCoupons). */
class RewardRule extends Model
{
    protected $fillable = ['name', 'is_active', 'min_books', 'min_amount', 'percent_off', 'max_discount', 'valid_days'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'min_books' => 'integer',
            'min_amount' => 'decimal:2',
            'percent_off' => 'decimal:2',
            'max_discount' => 'decimal:2',
            'valid_days' => 'integer',
        ];
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(Coupon::class);
    }
}

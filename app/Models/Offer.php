<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An automatic offer: "buy at least `min_quantity` covered books, get `percent_off` % off every covered book".
 * Rules: docs/superpowers/specs/2026-10-31-offers-design.md; the math lives in App\Services\Offers\OfferCalculator.
 */
class Offer extends Model
{
    use HasFactory;

    public const TYPE_BUY_N_PERCENT = 'buy_n_percent';

    public const APPLIES_TO_ALL = 'all';

    public const APPLIES_TO_SELECTED = 'selected';

    protected $fillable = [
        'name', 'type', 'applies_to', 'min_quantity', 'percent_off', 'starts_at', 'ends_at', 'max_orders', 'is_active',
    ];

    protected $attributes = [
        'type' => self::TYPE_BUY_N_PERCENT,
        'used_count' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'min_quantity' => 'integer',
            'percent_off' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'max_orders' => 'integer',
            'used_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(OfferTarget::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}

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

    public const STATUSES = ['live', 'scheduled', 'paused', 'ended', 'used_up', 'no_books'];

    /**
     * Spec 4.10, for the admin list: paused, ended, used up, no books (a "selected" offer whose targets are all gone
     * or in the trash), scheduled (starts later) or live. Needs `targets` with their book/category/series loaded.
     */
    public function status(): string
    {
        return match (true) {
            ! $this->is_active => 'paused',
            $this->ends_at !== null && $this->ends_at->lte(now()) => 'ended',
            $this->max_orders !== null && $this->used_count >= $this->max_orders => 'used_up',
            $this->applies_to === self::APPLIES_TO_SELECTED && ! $this->targets->contains(fn (OfferTarget $t) => ! $t->inTrash()) => 'no_books',
            $this->starts_at !== null && $this->starts_at->gt(now()) => 'scheduled',
            default => 'live',
        };
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

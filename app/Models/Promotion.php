<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A promotion the shop announces in a pop-up between two dates (owner 2026-11-12). It advertises only; the discount
 * is the linked automatic offer (optional). Staff switch it on and off with `is_active`.
 */
class Promotion extends Model
{
    use HasFactory;

    public const STATUSES = ['live', 'scheduled', 'hidden', 'ended'];

    protected $fillable = [
        'title_en', 'title_km', 'body_en', 'body_km', 'image_url', 'cta_label_en', 'cta_label_km', 'cta_url',
        'offer_id', 'starts_at', 'ends_at', 'is_active',
    ];

    protected $attributes = ['is_active' => false, 'cta_url' => '/books', 'views' => 0, 'clicks' => 0];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'views' => 'integer',
            'clicks' => 'integer',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /** Switched on, started and not ended. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function status(): string
    {
        return match (true) {
            ! $this->is_active => 'hidden',
            $this->ends_at !== null && $this->ends_at->lte(now()) => 'ended',
            $this->starts_at !== null && $this->starts_at->gt(now()) => 'scheduled',
            default => 'live',
        };
    }
}

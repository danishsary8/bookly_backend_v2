<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    use HasFactory;

    const UPDATED_AT = null;

    protected $fillable = ['base_currency', 'target_currency', 'rate', 'effective_at'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:6', 'effective_at' => 'datetime'];
    }

    public static function current(string $base, string $target): ?self
    {
        return static::at($base, $target, now());
    }

    /** The rate in force at a moment (e.g. when an order was placed), or null if none was set yet. */
    public static function at(string $base, string $target, DateTimeInterface $moment): ?self
    {
        return static::query()
            ->where('base_currency', $base)
            ->where('target_currency', $target)
            ->where('effective_at', '<=', $moment)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->first();
    }
}

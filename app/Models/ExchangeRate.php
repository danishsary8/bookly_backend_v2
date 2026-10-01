<?php

namespace App\Models;

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
        return static::query()
            ->where('base_currency', $base)
            ->where('target_currency', $target)
            ->where('effective_at', '<=', now())
            ->orderByDesc('effective_at')
            ->first();
    }
}

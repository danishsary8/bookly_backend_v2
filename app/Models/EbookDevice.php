<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A browser a customer reads ebooks on (max EbookDevices::MAX_DEVICES active). Only a hash of its id is kept. */
class EbookDevice extends Model
{
    protected $fillable = ['customer_id', 'device_hash', 'name', 'last_used_at', 'removed_at'];

    protected $hidden = ['device_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }
}

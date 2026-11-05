<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportMessage extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_internal' => 'boolean', 'created_at' => 'datetime', 'read_at' => 'datetime'];
    }
}

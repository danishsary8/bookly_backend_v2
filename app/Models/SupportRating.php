<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportRating extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['stars' => 'integer'];
    }
}

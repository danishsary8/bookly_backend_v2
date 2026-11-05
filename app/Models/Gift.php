<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Gift extends Model
{
    protected $fillable = ['name_en', 'name_km', 'image_url', 'stock', 'is_active'];

    protected function casts(): array
    {
        return ['stock' => 'integer', 'is_active' => 'boolean'];
    }
}

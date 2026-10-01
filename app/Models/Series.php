<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Series extends Model
{
    use HasFactory;

    protected $table = 'series';

    protected $fillable = ['name', 'description'];

    public function books(): HasMany
    {
        return $this->hasMany(Book::class)->orderBy('series_order');
    }
}

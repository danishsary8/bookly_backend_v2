<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CustomerTag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_customer_tag');
    }
}

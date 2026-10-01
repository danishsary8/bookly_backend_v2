<?php

namespace App\Models;

use App\Enums\VerificationPurpose;
use Illuminate\Database\Eloquent\Model;

class VerificationToken extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['user_type', 'user_id', 'purpose', 'code_hash', 'expires_at', 'used_at'];

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return [
            'purpose' => VerificationPurpose::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}

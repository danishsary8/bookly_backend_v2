<?php

namespace App\Models;

use App\Enums\StaffRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class StaffUser extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password_hash', 'role', 'two_factor_secret', 'two_factor_enabled'];

    protected $hidden = ['password_hash', 'two_factor_secret'];

    protected function casts(): array
    {
        return [
            'role' => StaffRole::class,
            'password_hash' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_enabled' => 'boolean',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function isAdmin(): bool
    {
        return $this->role === StaffRole::Admin;
    }

    /** Sanctum abilities granted to this staff member's tokens. */
    public function tokenAbilities(): array
    {
        return $this->isAdmin() ? ['staff', 'admin'] : ['staff'];
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AdminAuditLog::class);
    }
}

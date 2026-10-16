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

    protected $fillable = ['name', 'email', 'password_hash', 'role', 'permissions', 'two_factor_secret', 'two_factor_enabled', 'is_active'];

    protected $hidden = ['password_hash', 'two_factor_secret'];

    public const DEFAULT_PERMISSIONS = [
        'catalog:view',
        'catalog:manage',
        'orders:view',
        'orders:manage',
        'customers:view',
        'customers:manage',
        'reviews:manage',
    ];

    public const ADMIN_PERMISSIONS = [
        ...self::DEFAULT_PERMISSIONS,
        'reports:view',
        'members:manage',
        'settings:manage',
        'newsletter:manage',
    ];

    protected function casts(): array
    {
        return [
            'role' => StaffRole::class,
            'permissions' => 'array',
            'password_hash' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_enabled' => 'boolean',
            'is_active' => 'boolean',
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

    public function allPermissions(): array
    {
        if ($this->isAdmin()) {
            return self::ADMIN_PERMISSIONS;
        }

        return $this->permissions ?? self::DEFAULT_PERMISSIONS;
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return in_array($permission, $this->allPermissions(), true);
    }

    /** Sanctum abilities granted to this staff member's tokens. */
    public function tokenAbilities(): array
    {
        $abilities = ['staff', ...$this->allPermissions()];

        if ($this->isAdmin()) {
            $abilities[] = 'admin';
        }

        return array_values(array_unique($abilities));
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AdminAuditLog::class);
    }

    public function loginHistories(): HasMany
    {
        return $this->hasMany(StaffLoginHistory::class);
    }
}

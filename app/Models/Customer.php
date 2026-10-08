<?php

namespace App\Models;

use App\Services\Auth\TelegramGateway;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'email', 'password_hash', 'phone', 'phone_e164', 'google_id', 'facebook_id', 'email_verified_at', 'phone_verified_at', 'is_active'];

    protected $hidden = ['password_hash', 'google_id', 'facebook_id'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password_hash' => 'hashed',
        ];
    }

    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null && $this->phone_e164 !== null;
    }

    /** A real account: the customer proved their email or their phone (Telegram code). Only these can shop. */
    public function isVerified(): bool
    {
        return $this->hasVerifiedEmail() || $this->hasVerifiedPhone();
    }

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNotNull('email_verified_at')->orWhereNotNull('phone_verified_at'));
    }

    public function scopeUnverified(Builder $query): Builder
    {
        return $query->whereNull('email_verified_at')->whereNull('phone_verified_at');
    }

    /**
     * The ways this customer can sign in today: `password` (needs an email to type), `google`, `facebook`,
     * `phone` (a verified number, while Telegram codes are switched on). Account → Sign-in & security never
     * removes the last one.
     *
     * @return list<string>
     */
    public function signInMethods(): array
    {
        return array_values(array_filter([
            $this->password_hash !== null && $this->email !== null ? 'password' : null,
            $this->google_id !== null ? 'google' : null,
            $this->facebook_id !== null ? 'facebook' : null,
            $this->hasVerifiedPhone() && TelegramGateway::enabled() ? 'phone' : null,
        ]));
    }

    /** Saves a number the customer just proved with a Telegram code (also as their contact number). */
    public function markPhoneVerified(string $phoneE164): void
    {
        $this->forceFill([
            'phone_e164' => $phoneE164,
            'phone_verified_at' => now(),
            'phone' => PhoneNumber::display($phoneE164),
        ])->save();
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    public function wishlistBooks(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'wishlists')->withPivot('created_at');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OrderReturn::class);
    }
}

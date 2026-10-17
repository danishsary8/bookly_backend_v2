<?php

namespace App\Models;

use App\Notifications\WelcomeNotification;
use App\Services\Telegram\TelegramBot;
use App\Support\PhoneNumber;
use App\Support\SafeNotifier;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class Customer extends Authenticatable implements HasLocalePreference
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = ['name', 'email', 'locale', 'password_hash', 'phone', 'phone_e164', 'google_id', 'facebook_id', 'email_verified_at', 'phone_verified_at', 'is_active', 'telegram_chat_id', 'telegram_order_updates'];

    protected $hidden = ['password_hash', 'google_id', 'facebook_id'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password_hash' => 'hashed',
            'telegram_chat_id' => 'integer',
            'telegram_order_updates' => 'boolean',
        ];
    }

    public function hasTelegramOrderUpdates(): bool
    {
        return (bool) $this->telegram_order_updates && $this->telegram_chat_id !== null && TelegramBot::enabled();
    }

    /**
     * The welcome email goes out once, when the account first becomes verified, wherever that happens (email code,
     * Telegram phone, a verified Google/Facebook email at sign-up, a password reset by email code). After the
     * transaction commits, through SafeNotifier: a mail problem never blocks the sign-up.
     */
    protected static function booted(): void
    {
        // Created already verified (a verified Google/Facebook email at sign-up).
        static::created(function (Customer $customer) {
            if ($customer->isVerified()) {
                DB::afterCommit(fn () => SafeNotifier::toCustomer($customer, new WelcomeNotification));
            }
        });

        // Became verified later (email code, Telegram phone, password reset by email code). Only the change from
        // "not verified" to "verified" counts, so saving the same account again never sends a second email.
        static::updated(function (Customer $customer) {
            $wasVerified = $customer->getOriginal('email_verified_at') !== null
                || ($customer->getOriginal('phone_verified_at') !== null && $customer->getOriginal('phone_e164') !== null);

            if (! $wasVerified && $customer->isVerified()) {
                DB::afterCommit(fn () => SafeNotifier::toCustomer($customer, new WelcomeNotification));
            }
        });
    }

    public function preferredLocale(): string
    {
        return in_array($this->locale, ['en', 'km'], true) ? $this->locale : 'en';
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

    /** A real account: the customer proved their email or their phone (shared in the Telegram bot). Only these can shop. */
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
     * `telegram` (a verified number, while the Telegram bot is on). Account → Sign-in & security never
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
            $this->hasVerifiedPhone() && TelegramBot::enabled() ? 'telegram' : null,
        ]));
    }

    /** Saves a number the customer just proved in the Telegram bot (also as their contact number). */
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
        return $this->belongsToMany(Book::class, 'wishlists')->withPivot(['status', 'created_at']);
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

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class)->latest('created_at');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(CustomerTag::class, 'customer_customer_tag')->orderBy('name');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class NewsletterSubscriber extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'customer_id',
        'locale',
        'status',
        'confirm_token_hash',
        'unsubscribe_token_hash',
        'unsubscribe_token',
        'confirmed_at',
        'unsubscribed_at',
    ];

    protected $casts = [
        'confirmed_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'unsubscribe_token' => 'encrypted',
    ];

    protected $hidden = ['confirm_token_hash', 'unsubscribe_token_hash', 'unsubscribe_token'];

    /** A pending confirm link works this long after it was sent. */
    public const CONFIRM_DAYS = 7;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function generateRawToken(): string
    {
        return Str::random(48);
    }

    /** New random unsubscribe token for a new subscriber (the same token is used in every later email). */
    public static function unsubscribeFields(): array
    {
        $token = self::generateRawToken();

        return ['unsubscribe_token' => $token, 'unsubscribe_token_hash' => self::hashToken($token)];
    }

    public static function findByUnsubscribeToken(string $token): ?self
    {
        return self::where('unsubscribe_token_hash', self::hashToken(trim($token)))->first();
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

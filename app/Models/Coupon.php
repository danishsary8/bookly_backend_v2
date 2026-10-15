<?php

namespace App\Models;

use App\Enums\CouponType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'type', 'value', 'min_order_amount', 'max_uses', 'used_count', 'starts_at', 'expires_at', 'is_active',
        'user_id', 'is_store_credit',
    ];

    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'value' => 'decimal:2',
            'min_order_amount' => 'decimal:2',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'is_store_credit' => 'boolean',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'user_id');
    }

    /** Returns null when usable, otherwise the reason it cannot be applied. */
    public function unusableReason(string $subtotal, ?Customer $customer = null): ?string
    {
        return match (true) {
            ! $this->is_active => 'This coupon is not active.',
            $this->is_store_credit && $customer !== null && $this->user_id !== $customer->id => 'This store credit is not valid for your account.',
            $this->starts_at !== null && $this->starts_at->isFuture() => 'This coupon is not valid yet.',
            $this->expires_at !== null && $this->expires_at->isPast() => 'This coupon has expired.',
            $this->max_uses !== null && $this->used_count >= $this->max_uses => 'This coupon has reached its usage limit.',
            $this->min_order_amount !== null && Money::toCents($subtotal) < Money::toCents($this->min_order_amount) => "Order subtotal must be at least {$this->min_order_amount}.",
            default => null,
        };
    }

    /** Discount in cents for a subtotal in cents, never more than the subtotal. */
    public function discountCentsFor(int $subtotalCents): int
    {
        $discount = $this->type === CouponType::Percentage
            ? intdiv($subtotalCents * Money::toCents($this->value), 10000)
            : Money::toCents($this->value);

        return min($discount, $subtotalCents);
    }
}

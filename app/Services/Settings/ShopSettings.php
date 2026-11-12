<?php

namespace App\Services\Settings;

use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Settings staff change in admin (table `shop_settings`); a key without a row falls back to config/shop.php.
 * Values are short strings; an empty value means "off". Read on every checkout, so cached for a minute.
 */
class ShopSettings
{
    public const KEYS = ['khqr_discount_percent', 'cod_max_order'];

    private const CACHE = 'shop-settings';

    /** @return array<string, string|null> */
    public function all(): array
    {
        $rows = Cache::remember(self::CACHE, 60, fn () => DB::table('shop_settings')->pluck('value', 'key')->all());
        $values = [];
        foreach (self::KEYS as $key) {
            $value = array_key_exists($key, $rows) ? $rows[$key] : config("shop.{$key}");
            $values[$key] = $value === null || $value === '' ? null : (string) $value;
        }

        return $values;
    }

    /** @param  array<string, string|null>  $values */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            DB::table('shop_settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
        }
        Cache::forget(self::CACHE);
    }

    /** Percent off for paying by KHQR, as hundredths of a percent (3.00% = 300); 0 when off. */
    public function khqrDiscountBasisPoints(): int
    {
        $value = $this->all()['khqr_discount_percent'];

        return $value === null ? 0 : max(0, Money::toCents($value));
    }

    /** The most a cash on delivery order may cost, in cents; null = no limit. */
    public function codMaxCents(): ?int
    {
        $value = $this->all()['cod_max_order'];

        return $value === null ? null : Money::toCents($value);
    }
}

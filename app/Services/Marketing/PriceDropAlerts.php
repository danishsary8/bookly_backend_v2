<?php

namespace App\Services\Marketing;

use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\MarketingOutbox;
use App\Models\Wishlist;
use Database\Seeders\DemoSeeder;

/**
 * Wishlist price-drop emails (owner 2026-11-03): when a format's price goes down by at least MIN_DROP, every customer
 * who has the book on their "Want to read" list and turned on Account → Profile → price-drop emails gets one email,
 * at most one per book per COOLDOWN_DAYS. Offers ("buy 3, get 10% off") are not a lower price and never trigger it.
 * Mails go through the marketing outbox (daily cap, retries), which checks again just before sending.
 */
class PriceDropAlerts
{
    public const MIN_DROP = 0.05;

    public const COOLDOWN_DAYS = 7;

    public const KIND = 'price_drop';

    /** Called after a format's price was saved (every path: single edit, bulk edit, CSV import). Returns emails queued. */
    public function priceChanged(BookVariant $variant, int|float|string|null $oldPrice): int
    {
        $old = (float) $oldPrice;
        $new = (float) $variant->price_usd;
        if ($old <= 0 || ! self::isBigDrop($old, $new) || ! $variant->is_active) {
            return 0;
        }

        $book = Book::visible()->find($variant->book_id);
        if ($book === null) {
            return 0;
        }

        $customerIds = Wishlist::where('book_id', $book->id)->where('status', Wishlist::STATUS_WANT_TO_READ)->pluck('customer_id');
        $recentlyAlerted = MarketingOutbox::where('kind', self::KIND)
            ->whereIn('customer_id', $customerIds)
            ->where('status', '!=', 'cancelled')
            ->where('created_at', '>=', now()->subDays(self::COOLDOWN_DAYS))
            ->whereRaw("(payload->>'book_id')::bigint = ?", [$book->id])
            ->pluck('customer_id');

        $customers = Customer::whereIn('id', $customerIds->diff($recentlyAlerted))
            ->where('price_drop_alerts', true)
            ->where('is_active', true)
            ->whereNotNull('email_verified_at')
            ->whereNotNull('email')
            ->where('email', '!=', DemoSeeder::EMAIL) // shared account with a made-up address
            ->get();

        foreach ($customers as $customer) {
            MarketingOutbox::create([
                'kind' => self::KIND,
                'customer_id' => $customer->id,
                'email' => $customer->email,
                'locale' => $customer->preferredLocale(),
                'payload' => [
                    'book_id' => $book->id,
                    'book_title' => $book->title,
                    'variant_id' => $variant->id,
                    'format' => $variant->format instanceof \BackedEnum ? $variant->format->value : (string) $variant->format,
                    'old_price_usd' => number_format($old, 2, '.', ''),
                    'new_price_usd' => number_format($new, 2, '.', ''),
                    'customer_name' => $customer->name,
                ],
                'status' => 'pending',
            ]);
        }

        return $customers->count();
    }

    public static function isBigDrop(float $old, float $new): bool
    {
        // Compared in cents so $10.00 → $9.50 (exactly 5%) counts despite float rounding.
        $oldCents = (int) round($old * 100);
        $newCents = (int) round($new * 100);

        return $newCents < $oldCents && ($oldCents - $newCents) * 100 >= $oldCents * (int) round(self::MIN_DROP * 100);
    }

    /**
     * Just before sending: why this email should not go out any more (null = send). The customer may have turned the
     * emails off, removed the book or started reading it, or the price may have gone back up.
     */
    public function staleReason(MarketingOutbox $row): ?string
    {
        $customer = $row->customer;
        if ($customer === null || ! $customer->price_drop_alerts || ! $customer->is_active) {
            return 'Customer turned price-drop emails off.';
        }
        $bookId = (int) ($row->payload['book_id'] ?? 0);
        if (! Wishlist::where('customer_id', $customer->id)->where('book_id', $bookId)->where('status', Wishlist::STATUS_WANT_TO_READ)->exists()) {
            return 'Book is no longer on the wishlist.';
        }
        $variant = BookVariant::find($row->payload['variant_id'] ?? 0);
        if ($variant === null || ! $variant->is_active || (float) $variant->price_usd > (float) ($row->payload['new_price_usd'] ?? 0)) {
            return 'The lower price is gone.';
        }

        return null;
    }
}

<?php

namespace App\Services\Marketing;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\MarketingOutbox;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cart reminder emails (owner 2026-11-05): a signed-in customer who leaves books in the cart for WAIT_HOURS gets one
 * friendly email. On by default; every email has a one-click "stop" link, and Account → Profile has a switch.
 * One email per cart change, and never more than one per MIN_GAP_DAYS. Carts untouched for longer than MAX_AGE_DAYS
 * are left alone (too late to be helpful). Mails go through the marketing outbox (daily cap, retries), which checks
 * again just before sending.
 */
class CartReminders
{
    public const KIND = 'cart_reminder';

    public const WAIT_HOURS = 24;

    public const MIN_GAP_DAYS = 7;

    public const MAX_AGE_DAYS = 7;

    /** Titles listed in the email; the rest is "and N more". */
    public const LISTED_ITEMS = 3;

    /** When the customer last changed the cart: the cart row or its newest line (a removed line touches the cart). */
    private const LAST_CHANGE = 'greatest(carts.updated_at, coalesce((select max(ci.updated_at) from cart_items ci where ci.cart_id = carts.id), carts.updated_at))';

    /** Queues the reminders that are due. Returns how many were queued. */
    public function queueDue(int $limit = 200): int
    {
        $last = self::LAST_CHANGE;
        $carts = Cart::query()
            ->select('carts.*')
            ->selectRaw("{$last} as last_change_at")
            ->whereHas('items')
            ->whereHas('customer', fn ($q) => $q
                ->where('cart_reminders', true)
                ->where('is_active', true)
                ->whereNotNull('email_verified_at')
                ->whereNotNull('email')
                ->where('email', '!=', DemoSeeder::EMAIL)) // shared account with a made-up address
            ->whereRaw("{$last} <= ?", [now()->subHours(self::WAIT_HOURS)])
            ->whereRaw("{$last} >= ?", [now()->subDays(self::MAX_AGE_DAYS)])
            ->where(fn ($q) => $q->whereNull('reminder_sent_at')->orWhere(fn ($q) => $q
                ->where('reminder_sent_at', '<', now()->subDays(self::MIN_GAP_DAYS))
                ->whereRaw("reminder_sent_at < {$last}")))
            ->with(['customer', 'items' => fn ($q) => $q->orderBy('id'), 'items.variant.book'])
            ->orderBy('carts.id')
            ->limit($limit)
            ->get();

        foreach ($carts as $cart) {
            $customer = $cart->customer;
            $lines = $cart->items->filter(fn ($item) => $item->variant?->book !== null);
            if ($lines->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($cart, $customer, $lines) {
                MarketingOutbox::create([
                    'kind' => self::KIND,
                    'customer_id' => $customer->id,
                    'email' => $customer->email,
                    'locale' => $customer->preferredLocale(),
                    'payload' => [
                        'cart_id' => $cart->id,
                        'customer_name' => $customer->name,
                        'titles' => $lines->take(self::LISTED_ITEMS)->map(fn ($item) => $item->variant->book->title)->values()->all(),
                        'more' => max(0, $lines->count() - self::LISTED_ITEMS),
                    ],
                    'status' => 'pending',
                ]);
                // Straight to the table: saving the model would bump updated_at, which counts as cart activity.
                DB::table('carts')->where('id', $cart->id)->update(['reminder_sent_at' => now()]);
            });
        }

        return $carts->count();
    }

    /**
     * Just before sending: why this email should not go out any more (null = send). The customer may have turned the
     * emails off, checked out, emptied the cart or come back to it since it was queued.
     */
    public function staleReason(MarketingOutbox $row): ?string
    {
        $customer = $row->customer;
        if ($customer === null || ! $customer->cart_reminders || ! $customer->is_active) {
            return 'Customer turned cart reminders off.';
        }
        $cart = Cart::query()->select('carts.*')->selectRaw(self::LAST_CHANGE.' as last_change_at')
            ->where('customer_id', $customer->id)->first();
        if ($cart === null || ! $cart->items()->exists()) {
            return 'The cart is empty now.';
        }
        if (Carbon::parse($cart->last_change_at)->gt($row->created_at)) {
            return 'The customer came back to the cart.';
        }

        return null;
    }

    /** The secret in the "stop these emails" link (no sign-in needed; works until the app key changes). */
    public static function stopToken(Customer $customer): string
    {
        return hash_hmac('sha256', 'cart-reminders-stop|'.$customer->id, (string) config('app.key'));
    }

    /** Turns the emails off for the customer the link belongs to. False when the link is wrong. */
    public function stop(int $customerId, string $token): bool
    {
        $customer = Customer::find($customerId);
        if ($customer === null || ! hash_equals(self::stopToken($customer), $token)) {
            return false;
        }
        $customer->forceFill(['cart_reminders' => false])->save();

        return true;
    }

    /** @return array{web: string, one_click: string} */
    public static function stopUrls(Customer $customer): array
    {
        $shop = rtrim((string) (config('shop.url') ?: url('')), '/');
        $query = http_build_query(['customer' => $customer->id, 'token' => self::stopToken($customer)]);

        return [
            'web' => $shop.'/email/cart-reminders/stop?'.$query,
            'one_click' => url('/api/v1/cart-reminders/stop/one-click?'.$query),
        ];
    }
}

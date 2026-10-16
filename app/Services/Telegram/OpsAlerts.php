<?php

namespace App\Services\Telegram;

use App\Models\BookVariant;
use App\Models\Order;
use App\Models\Review;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Alerts on Telegram through the separate "Bookly Alerts" bot: new order, low stock and 1-2 star reviews to the owner's
 * chat (OPS_ALERT_CHAT_ID); new orders and low stock also to the staff group (OPS_STAFF_CHAT_ID), where the bot is a
 * member. Best effort by design: each chat is off until its setting exists, sending runs after the response, and any
 * failure is only logged (without the bot token), so it can never block or slow an order.
 */
class OpsAlerts
{
    public static function enabled(): bool
    {
        return (string) config('services.ops_alerts.token') !== '' && self::chats(staff: true) !== [];
    }

    public function newOrder(Order $order): void
    {
        $order->loadMissing(['customer', 'items.variant.book']);
        $lines = $order->items->map(fn ($item) => "• {$item->quantity} × {$item->bookTitle()} ({$item->bookFormat()?->value})");
        $shop = rtrim((string) config('shop.url'), '/');
        $note = $order->delivery_note !== null && $order->delivery_note !== ''
            ? 'Note: '.Str::limit($order->delivery_note, 300)
            : null;

        $this->send(implode("\n", array_filter([
            "🛒 New order {$order->order_number}",
            "Total: {$order->total_amount} USD ({$order->items->sum('quantity')} items)",
            ...$lines->take(10)->all(),
            $lines->count() > 10 ? '• … and '.($lines->count() - 10).' more lines' : null,
            "Customer: {$order->customer?->name}",
            "Delivery: {$order->shipping_city}, {$order->shipping_country}",
            $note,
            'Payment: cash on delivery',
            $shop !== '' ? "Open: {$shop}/admin/orders/{$order->id}" : null,
        ])), staff: true);
    }

    public function lowStock(BookVariant $variant): void
    {
        $variant->loadMissing('book');

        $this->send(implode("\n", [
            '⚠️ Low stock',
            ($variant->book?->title ?? 'Book')." ({$variant->format->value})",
            "Left: {$variant->stock_quantity} (alert at {$variant->low_stock_threshold})",
        ]), staff: true);
    }

    /** A 1 or 2 star review: the owner can answer it or hide it in the admin (Reviews). The comment is shortened. */
    public function lowRatedReview(Review $review, bool $edited = false): void
    {
        $review->loadMissing(['book', 'customer']);
        $comment = trim((string) $review->comment);

        $this->send(implode("\n", array_filter([
            ($edited ? '✏️ Review changed to ' : '⭐ New ').str_repeat('★', (int) $review->rating).str_repeat('☆', 5 - (int) $review->rating).' review',
            'Book: '.($review->book?->title ?? 'Book'),
            'Customer: '.($review->customer?->name ?? 'Customer'),
            $comment !== '' ? '"'.Str::limit($comment, 300).'"' : null,
            "Admin → Reviews, review #{$review->id}",
        ])));
    }

    public function customerClosedByAdmin(string $adminName, string $customerName, CarbonInterface|string $reopenUntil): void
    {
        $date = $reopenUntil instanceof CarbonInterface ? $reopenUntil->toFormattedDayDateString() : $reopenUntil;
        $this->send("Customer account closed by {$adminName}: {$customerName}, can be reopened until {$date}");
    }

    public function itemDeletedByAdmin(string $adminName, string $entityType, string $itemName): void
    {
        $this->send("🗑️ {$entityType} \"{$itemName}\" moved to trash by {$adminName} (30-day restore window)");
    }

    public function bookDeletedByAdmin(string $adminName, string $bookTitle, CarbonInterface|string $restoreUntil): void
    {
        $date = $restoreUntil instanceof CarbonInterface ? $restoreUntil->toFormattedDayDateString() : $restoreUntil;
        $this->send("Book moved to trash by {$adminName}: {$bookTitle}, can be restored until {$date}");
    }

    /** The chats a message goes to: the owner's, plus the staff group for staff messages; the same chat only once. */
    private static function chats(bool $staff): array
    {
        $chats = [(string) config('services.ops_alerts.chat_id'), $staff ? (string) config('services.ops_alerts.staff_chat_id') : ''];

        return array_values(array_unique(array_filter($chats, fn (string $chat) => $chat !== '')));
    }

    /** Sends after the HTTP response (or at once in the console), one message per chat. Never throws. */
    private function send(string $text, bool $staff = false): void
    {
        $chats = self::chats($staff);
        if ((string) config('services.ops_alerts.token') === '' || $chats === []) {
            return;
        }

        defer(function () use ($text, $chats) {
            foreach ($chats as $chat) {
                try {
                    $response = Http::timeout(5)->connectTimeout(3)->post(
                        rtrim((string) config('services.ops_alerts.url'), '/').'/bot'.config('services.ops_alerts.token').'/sendMessage',
                        ['chat_id' => $chat, 'text' => $text],
                    );

                    if ($response->failed()) {
                        Log::warning('Ops alert was not delivered', ['status' => $response->status(), 'reason' => $response->json('description')]);
                    }
                } catch (Throwable $e) {
                    // The exception text can contain the request URL (and so the token): log only its class.
                    Log::warning('Ops alert could not be sent', ['error' => $e::class]);
                }
            }
        });
    }
}

<?php

namespace App\Services\Telegram;

use App\Models\BookVariant;
use App\Models\Order;
use App\Models\Review;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Owner alerts on Telegram through the separate "Bookly Alerts" bot (new order, low stock, 1-2 star review).
 * Best effort by design: it is off until both settings exist, it runs after the response is sent,
 * and any failure is only logged (without the bot token), so it can never block or slow an order.
 */
class OpsAlerts
{
    public static function enabled(): bool
    {
        return (string) config('services.ops_alerts.token') !== '' && (string) config('services.ops_alerts.chat_id') !== '';
    }

    public function newOrder(Order $order): void
    {
        $order->loadMissing('customer');

        $this->send(implode("\n", [
            "🛒 New order {$order->order_number}",
            "Total: {$order->total_amount} USD ({$order->items()->sum('quantity')} items)",
            "Customer: {$order->customer?->name}",
            "Delivery: {$order->shipping_city}, {$order->shipping_country}",
            'Payment: cash on delivery',
        ]));
    }

    public function lowStock(BookVariant $variant): void
    {
        $variant->loadMissing('book');

        $this->send(implode("\n", [
            '⚠️ Low stock',
            ($variant->book?->title ?? 'Book')." ({$variant->format->value})",
            "Left: {$variant->stock_quantity} (alert at {$variant->low_stock_threshold})",
        ]));
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

    /** Sends after the HTTP response (or at once in the console). Never throws. */
    private function send(string $text): void
    {
        if (! self::enabled()) {
            return;
        }

        defer(function () use ($text) {
            try {
                $response = Http::timeout(5)->connectTimeout(3)->post(
                    rtrim((string) config('services.ops_alerts.url'), '/').'/bot'.config('services.ops_alerts.token').'/sendMessage',
                    ['chat_id' => config('services.ops_alerts.chat_id'), 'text' => $text],
                );

                if ($response->failed()) {
                    Log::warning('Ops alert was not delivered', ['status' => $response->status(), 'reason' => $response->json('description')]);
                }
            } catch (Throwable $e) {
                // The exception text can contain the request URL (and so the token): log only its class.
                Log::warning('Ops alert could not be sent', ['error' => $e::class]);
            }
        });
    }
}

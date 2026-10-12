<?php

namespace App\Services\Telegram;

use App\Models\BookVariant;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Owner alerts on Telegram through the separate "Bookly Alerts" bot (new order, low stock).
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

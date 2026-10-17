<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends real-time order notifications to customers on Telegram via the Bookly bot
 * when they have connected their Telegram chat and enabled order updates.
 *
 * Best effort by design: all dispatches run safely and any Telegram API issues
 * are logged so that order creation and status transitions are never blocked.
 */
class CustomerOrderUpdates
{
    public function __construct(private readonly TelegramBot $bot) {}

    public function orderPlaced(Order $order): void
    {
        $customer = $order->customer;
        if ($customer === null || ! $customer->hasTelegramOrderUpdates()) {
            return;
        }

        $order->loadMissing(['items.variant.book']);
        $isKhmer = $customer->preferredLocale() === 'km';
        $shopUrl = rtrim((string) config('shop.url', config('app.url')), '/');
        $orderUrl = "{$shopUrl}/account/orders/{$order->id}";

        $lines = $order->items->map(function ($item) {
            $title = $this->esc($item->bookTitle() ?? 'Book');
            $format = $item->bookFormat()?->value ?? '';
            $formatStr = $format !== '' ? " ({$this->esc($format)})" : '';

            return "• {$item->quantity} × {$title}{$formatStr}";
        });

        $displayedLines = $lines->take(8)->all();
        if ($lines->count() > 8) {
            $displayedLines[] = $isKhmer ? '• … និងមុខទំនិញផ្សេងទៀត' : '• … and more items';
        }

        $safeOrderNumber = $this->esc($order->order_number);
        $safeTotal = $this->esc($order->total_amount);
        $safeUrl = $this->esc($orderUrl);

        if ($isKhmer) {
            $message = implode("\n", array_filter([
                '📚 <b>ការបញ្ជាទិញ Bookly បានជោគជ័យ!</b>',
                "លេខកូដបញ្ជាទិញ: <b>#{$safeOrderNumber}</b>",
                "សរុប: <b>\${$safeTotal} USD</b> ({$order->items->sum('quantity')} ក្បាល)",
                'ការទូទាត់: ប្រគល់លុយពេលទទួលទំនិញ (COD)',
                '',
                '<b>មុខទំនិញ:</b>',
                ...$displayedLines,
                '',
                'យើងកំពុងរៀបចំសៀវភៅរបស់អ្នក! យើងនឹងផ្ញើដំណឹងជូនអ្នកពេលកញ្ចប់ទំនិញត្រូវបានបញ្ជូន។',
                "<a href=\"{$safeUrl}\">ពិនិត្យមើលការបញ្ជាទិញរបស់អ្នកនៅលើវេបសាយ</a>",
            ]));
        } else {
            $message = implode("\n", array_filter([
                '📚 <b>Bookly Order Confirmed!</b>',
                "Order: <b>#{$safeOrderNumber}</b>",
                "Total: <b>\${$safeTotal} USD</b> ({$order->items->sum('quantity')} items)",
                'Payment: Cash on delivery (COD)',
                '',
                '<b>Items:</b>',
                ...$displayedLines,
                '',
                "We are packing your books! We'll notify you as soon as your package is dispatched.",
                "<a href=\"{$safeUrl}\">View your order details online</a>",
            ]));
        }

        $this->send((int) $customer->telegram_chat_id, $message);
    }

    public function orderStatusChanged(Order $order, OrderStatus $status, ?string $note = null): void
    {
        $customer = $order->customer;
        if ($customer === null || ! $customer->hasTelegramOrderUpdates()) {
            return;
        }

        if (! in_array($status, [OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Cancelled], true)) {
            return;
        }

        $isKhmer = $customer->preferredLocale() === 'km';
        $shopUrl = rtrim((string) config('shop.url', config('app.url')), '/');
        $orderUrl = "{$shopUrl}/account/orders/{$order->id}";

        $safeOrderNumber = $this->esc($order->order_number);
        $safeUrl = $this->esc($orderUrl);
        $safeNote = filled($note) ? $this->esc($note) : null;
        $safeTotal = $this->esc($order->total_amount);

        $message = match ($status) {
            OrderStatus::Processing => $isKhmer
                ? implode("\n", array_filter([
                    '📦 <b>កញ្ចប់ទំនិញកំពុងរៀបចំ</b>',
                    "លេខកូដបញ្ជាទិញ: <b>#{$safeOrderNumber}</b>",
                    'សៀវភៅរបស់អ្នកកំពុងត្រូវបានវេចខ្ចប់យ៉ាងយកចិត្តទុកដាក់ និងត្រៀមបញ្ជូន។',
                    $safeNote !== null ? "កំណត់សម្គាល់: {$safeNote}" : null,
                    "<a href=\"{$safeUrl}\">តាមដានស្ថានភាពការបញ្ជាទិញ</a>",
                ]))
                : implode("\n", array_filter([
                    '📦 <b>Order Being Processed</b>',
                    "Order: <b>#{$safeOrderNumber}</b>",
                    'Your books are being carefully packed and prepared for delivery.',
                    $safeNote !== null ? "Note from shop: {$safeNote}" : null,
                    "<a href=\"{$safeUrl}\">Track your order progress</a>",
                ])),

            OrderStatus::Shipped => $isKhmer
                ? implode("\n", array_filter([
                    '🚚 <b>ការបញ្ជាទិញត្រូវបានបញ្ជូនចេញហើយ!</b>',
                    "លេខកូដបញ្ជាទិញ: <b>#{$safeOrderNumber}</b>",
                    'កញ្ចប់សៀវភៅរបស់អ្នកកំពុងធ្វើដំណើរទៅកាន់អ្នក។ អ្នកដឹកជញ្ជូននឹងទាក់ទងមកអ្នកតាមទូរស័ព្ទ។',
                    $safeNote !== null ? "ព័ត៌មានដឹកជញ្ជូន: {$safeNote}" : null,
                    "<a href=\"{$safeUrl}\">ពិនិត្យព័ត៌មានលម្អិត</a>",
                ]))
                : implode("\n", array_filter([
                    '🚚 <b>Order On Its Way!</b>',
                    "Order: <b>#{$safeOrderNumber}</b>",
                    'Your package has been dispatched and is on its way to you.',
                    $safeNote !== null ? "Delivery note: {$safeNote}" : null,
                    "<a href=\"{$safeUrl}\">View delivery details</a>",
                ])),

            OrderStatus::Delivered => $isKhmer
                ? implode("\n", array_filter([
                    '🎉 <b>ការបញ្ជាទិញត្រូវបានប្រគល់ជូនជោគជ័យ!</b>',
                    "លេខកូដបញ្ជាទិញ: <b>#{$safeOrderNumber}</b>",
                    "ទឹកប្រាក់បានទូទាត់: <b>\${$safeTotal} USD</b>",
                    'អរគុណច្រើនដែលបានទិញសៀវភៅពី Bookly! យើងសង្ឃឹមថាអ្នកនឹងរីករាយជាមួយការអាន។',
                    "<a href=\"{$safeUrl}\">មើលវិក្កយបត្រ ឬស្នើសុំប្តូរទំនិញ</a>",
                ]))
                : implode("\n", array_filter([
                    '🎉 <b>Order Delivered!</b>',
                    "Order: <b>#{$safeOrderNumber}</b>",
                    "Total collected: <b>\${$safeTotal} USD</b>",
                    'Thank you for choosing Bookly! We hope you enjoy reading your books.',
                    "<a href=\"{$safeUrl}\">View receipt & order details</a>",
                ])),

            default => $isKhmer
                ? implode("\n", array_filter([
                    '❌ <b>ការបញ្ជាទិញត្រូវបានលុបចោល</b>',
                    "លេខកូដបញ្ជាទិញ: <b>#{$safeOrderNumber}</b>",
                    $safeNote !== null ? "មូលហេតុ: {$safeNote}" : 'ការបញ្ជាទិញរបស់អ្នកត្រូវបានលុបចោល។',
                    "<a href=\"{$safeUrl}\">ពិនិត្យការបញ្ជាទិញ</a>",
                ]))
                : implode("\n", array_filter([
                    '❌ <b>Order Cancelled</b>',
                    "Order: <b>#{$safeOrderNumber}</b>",
                    $safeNote !== null ? "Reason: {$safeNote}" : 'Your order has been cancelled.',
                    "<a href=\"{$safeUrl}\">View order details</a>",
                ])),
        };

        $this->send((int) $customer->telegram_chat_id, $message);
    }

    private function esc(?string $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function send(int $chatId, string $html): void
    {
        try {
            $this->bot->call('sendMessage', [
                'chat_id' => $chatId,
                'text' => $html,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to dispatch customer Telegram order update: '.$e->getMessage(), [
                'chat_id' => $chatId,
            ]);
        }
    }
}

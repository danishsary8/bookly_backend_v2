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
            $title = $item->bookTitle() ?? 'Book';
            $format = $item->bookFormat()?->value ?? '';
            $formatStr = $format !== '' ? " ({$format})" : '';

            return "• {$item->quantity} × {$title}{$formatStr}";
        });

        $displayedLines = $lines->take(8)->all();
        if ($lines->count() > 8) {
            $displayedLines[] = $isKhmer ? '• … និងមុខទំនិញផ្សេងទៀត' : '• … and more items';
        }

        if ($isKhmer) {
            $message = implode("\n", array_filter([
                '📚 <b>ការបញ្ជាទិញ Bookly បានជោគជ័យ!</b>',
                "លេខកូដបញ្ជាទិញ: <b>#{$order->order_number}</b>",
                "សរុប: <b>\${$order->total_amount} USD</b> ({$order->items->sum('quantity')} ក្បាល)",
                'ការទូទាត់: ប្រគល់លុយពេលទទួលទំនិញ (COD)',
                '',
                '<b>មុខទំនិញ:</b>',
                ...$displayedLines,
                '',
                'យើងកំពុងរៀបចំសៀវភៅរបស់អ្នក! យើងនឹងផ្ញើដំណឹងជូនអ្នកពេលកញ្ចប់ទំនិញត្រូវបានបញ្ជូន។',
                "<a href=\"{$orderUrl}\">ពិនិត្យមើលការបញ្ជាទិញរបស់អ្នកនៅលើវេបសាយ</a>",
            ]));
        } else {
            $message = implode("\n", array_filter([
                '📚 <b>Bookly Order Confirmed!</b>',
                "Order: <b>#{$order->order_number}</b>",
                "Total: <b>\${$order->total_amount} USD</b> ({$order->items->sum('quantity')} items)",
                'Payment: Cash on delivery (COD)',
                '',
                '<b>Items:</b>',
                ...$displayedLines,
                '',
                "We are packing your books! We'll notify you as soon as your package is dispatched.",
                "<a href=\"{$orderUrl}\">View your order details online</a>",
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

        $message = match ($status) {
            OrderStatus::Processing => $isKhmer
                ? implode("\n", array_filter([
                    '📦 <b>កញ្ចប់ទំនិញកំពុងរៀបចំ</b>',
                    "លេខកូដបញ្ជាទិញ: <b>#{$order->order_number}</b>",
                    'សៀវភៅរបស់អ្នកកំពុងត្រូវបានវេចខ្ចប់យ៉ាងយកចិត្តទុកដាក់ និងត្រៀមបញ្ជូន។',
                    filled($note) ? "កំណត់សម្គាល់: {$note}" : null,
                    "<a href=\"{$orderUrl}\">តាមដានស្ថានភាពការបញ្ជាទិញ</a>",
                ]))
                : implode("\n", array_filter([
                    '📦 <b>Order Being Processed</b>',
                    "Order: <b>#{$order->order_number}</b>",
                    'Your books are being carefully packed and prepared for delivery.',
                    filled($note) ? "Note from shop: {$note}" : null,
                    "<a href=\"{$orderUrl}\">Track your order progress</a>",
                ])),

            OrderStatus::Shipped => $isKhmer
                ? implode("\n", array_filter([
                    '🚚 <b>ការបញ្ជាទិញត្រូវបានបញ្ជូនចេញហើយ!</b>',
                    "លេខកូដបញ្ជាទិញ: <b>#{$order->order_number}</b>",
                    'កញ្ចប់សៀវភៅរបស់អ្នកកំពុងធ្វើដំណើរទៅកាន់អ្នក។ អ្នកដឹកជញ្ជូននឹងទាក់ទងមកអ្នកតាមទូរស័ព្ទ។',
                    filled($note) ? "ព័ត៌មានដឹកជញ្ជូន: {$note}" : null,
                    "<a href=\"{$orderUrl}\">ពិនិត្យព័ត៌មានលម្អិត</a>",
                ]))
                : implode("\n", array_filter([
                    '🚚 <b>Order On Its Way!</b>',
                    "Order: <b>#{$order->order_number}</b>",
                    'Your package has been dispatched and is on its way to you.',
                    filled($note) ? "Delivery note: {$note}" : null,
                    "<a href=\"{$orderUrl}\">View delivery details</a>",
                ])),

            OrderStatus::Delivered => $isKhmer
                ? implode("\n", array_filter([
                    '🎉 <b>ការបញ្ជាទិញត្រូវបានប្រគល់ជូនជោគជ័យ!</b>',
                    "លេខកូដបញ្ជាទិញ: <b>#{$order->order_number}</b>",
                    "ទឹកប្រាក់បានទូទាត់: <b>\${$order->total_amount} USD</b>",
                    'អរគុណច្រើនដែលបានទិញសៀវភៅពី Bookly! យើងសង្ឃឹមថាអ្នកនឹងរីករាយជាមួយការអាន។',
                    "<a href=\"{$orderUrl}\">មើលវិក្កយបត្រ ឬស្នើសុំប្តូរទំនិញ</a>",
                ]))
                : implode("\n", array_filter([
                    '🎉 <b>Order Delivered!</b>',
                    "Order: <b>#{$order->order_number}</b>",
                    "Total collected: <b>\${$order->total_amount} USD</b>",
                    'Thank you for choosing Bookly! We hope you enjoy reading your books.',
                    "<a href=\"{$orderUrl}\">View receipt & order details</a>",
                ])),

            default => $isKhmer
                ? implode("\n", array_filter([
                    '❌ <b>ការបញ្ជាទិញត្រូវបានលុបចោល</b>',
                    "លេខកូដបញ្ជាទិញ: <b>#{$order->order_number}</b>",
                    filled($note) ? "មូលហេតុ: {$note}" : 'ការបញ្ជាទិញរបស់អ្នកត្រូវបានលុបចោល។',
                    "<a href=\"{$orderUrl}\">ពិនិត្យការបញ្ជាទិញ</a>",
                ]))
                : implode("\n", array_filter([
                    '❌ <b>Order Cancelled</b>',
                    "Order: <b>#{$order->order_number}</b>",
                    filled($note) ? "Reason: {$note}" : 'Your order has been cancelled.',
                    "<a href=\"{$orderUrl}\">View order details</a>",
                ])),
        };

        $this->send((int) $customer->telegram_chat_id, $message);
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

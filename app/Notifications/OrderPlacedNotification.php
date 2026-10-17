<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderPlacedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Order $order)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $order = $this->order->loadMissing(['items.variant.book' => fn ($q) => $q->withTrashed()]);
        $mail = (new MailMessage)
            ->subject(__('mail.order_placed_subject', ['number' => $order->order_number]))
            ->greeting(__('mail.order_placed_greeting', ['name' => $notifiable->name]))
            ->line(__('mail.order_number', ['number' => $order->order_number]));

        foreach ($order->items as $item) {
            $mail->line("{$item->quantity} x {$item->bookTitle()} ({$item->bookFormat()?->value}) — {$item->subtotal} USD");
        }

        $mail->line(__('mail.subtotal', ['amount' => $order->subtotal]));
        if ((float) $order->discount_amount > 0) {
            $mail->line(__('mail.discount', ['amount' => $order->discount_amount]));
        }

        $mail->line(__('mail.shipping', ['amount' => $order->shipping_fee]))
            ->line(__('mail.total', ['amount' => $order->total_amount]))
            ->line(__('mail.payment_cod'))
            ->line(__('mail.delivery_to', [
                'recipient' => $order->shipping_recipient_name,
                'address' => $order->shipping_address_line1,
                'city' => $order->shipping_city,
                'country' => $order->shipping_country,
            ]));

        $shop = rtrim((string) (config('shop.url') ?: config('app.url')), '/');
        if ($shop !== '') {
            $mail->action(__('mail.view_order'), "{$shop}/account/orders/{$order->id}");
        }

        return $mail;
    }
}

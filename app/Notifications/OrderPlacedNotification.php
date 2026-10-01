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
            ->subject("Order confirmed: {$order->order_number}")
            ->greeting("Thank you for your order, {$notifiable->name}!")
            ->line("Order number: {$order->order_number}");

        foreach ($order->items as $item) {
            $mail->line("{$item->quantity} x {$item->variant->book?->title} ({$item->variant->format->value}) — {$item->subtotal} USD");
        }

        $mail->line("Subtotal: {$order->subtotal} USD");
        if ((float) $order->discount_amount > 0) {
            $mail->line("Discount: -{$order->discount_amount} USD");
        }

        return $mail
            ->line("Shipping: {$order->shipping_fee} USD")
            ->line("Total: {$order->total_amount} USD")
            ->line('Payment: cash on delivery.')
            ->line("Delivery to: {$order->shipping_recipient_name}, {$order->shipping_address_line1}, {$order->shipping_city}, {$order->shipping_country}");
    }
}

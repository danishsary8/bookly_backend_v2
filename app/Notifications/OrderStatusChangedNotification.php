<?php

namespace App\Notifications;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the customer about processing, shipped, delivered and cancelled. The staff note (e.g. tracking number) is included. */
class OrderStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Order $order,
        public readonly OrderStatus $status,
        public readonly ?string $note = null,
    ) {}

    /** Statuses that produce an email. `paid`/`returned` are internal; the return emails cover `returned`. */
    public static function covers(OrderStatus $status): bool
    {
        return in_array($status, [OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered, OrderStatus::Cancelled], true);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $number = $this->order->order_number;

        [$subject, $intro] = match ($this->status) {
            OrderStatus::Processing => ["We are packing your order {$number}", 'Good news: your order is confirmed and we are preparing it for shipping.'],
            OrderStatus::Shipped => ["Your order {$number} is on its way", 'Your order has been shipped.'],
            OrderStatus::Delivered => ["Your order {$number} was delivered", 'Your order has been delivered. Thank you for shopping with us!'],
            default => ["Your order {$number} was cancelled", 'Your order has been cancelled.'],
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line($intro)
            ->line("Order number: {$number}");

        if (filled($this->note)) {
            $mail->line("Note from the shop: {$this->note}");
        }

        if ($this->status === OrderStatus::Delivered) {
            $mail->line("Receipt: {$this->order->total_amount} USD (subtotal {$this->order->subtotal}, shipping {$this->order->shipping_fee}, discount {$this->order->discount_amount}). Paid on delivery.")
                ->line('If something is wrong with your books you can request a return from your account within the return period.');
        }

        if ($this->status === OrderStatus::Cancelled) {
            $mail->line('Any stock or coupon use was released. If you did not ask for this, please contact support.');
        }

        return $mail;
    }
}

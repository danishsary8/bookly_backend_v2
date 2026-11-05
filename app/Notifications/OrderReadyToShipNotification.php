<?php

namespace App\Notifications;

use App\Models\Order;
use App\Notifications\Concerns\SendsAfterResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderReadyToShipNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsAfterResponse;

    public function __construct(
        public readonly Order $order,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $number = $this->order->order_number;

        return (new MailMessage)
            ->subject("Order {$number} is ready to ship")
            ->greeting("Hello {$notifiable->name},")
            ->line("All stock and pre-order items for order {$number} have arrived and are allocated.")
            ->line('The order is now ready for packing and shipping.')
            ->action('View Order in Admin', url('/admin/orders'));
    }
}

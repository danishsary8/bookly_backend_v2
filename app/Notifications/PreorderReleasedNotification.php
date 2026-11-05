<?php

namespace App\Notifications;

use App\Models\BookVariant;
use App\Models\Order;
use App\Notifications\Concerns\SendsAfterResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PreorderReleasedNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsAfterResponse;

    public function __construct(
        public readonly Order $order,
        public readonly BookVariant $variant,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->variant->book?->title ?? 'Your pre-ordered book';
        $number = $this->order->order_number;

        return (new MailMessage)
            ->subject(__('mail.preorder_released_subject', ['title' => $title]))
            ->greeting(__('mail.hello_greeting', ['name' => $notifiable->name]))
            ->line(__('mail.preorder_released_intro', ['title' => $title, 'number' => $number]))
            ->line(__('mail.order_number', ['number' => $number]))
            ->action(__('mail.view_order'), url('/account/orders/'.$this->order->id));
    }
}

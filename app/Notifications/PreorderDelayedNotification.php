<?php

namespace App\Notifications;

use App\Models\BookVariant;
use App\Models\Order;
use App\Notifications\Concerns\SendsAfterResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PreorderDelayedNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsAfterResponse;

    public function __construct(
        public readonly Order $order,
        public readonly BookVariant $variant,
        public readonly string $newDate,
        public readonly ?string $note = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $title = $this->variant->book?->title ?? 'Your pre-ordered book';
        $number = $this->order->order_number;

        $mail = (new MailMessage)
            ->subject(__('mail.preorder_delayed_subject', ['title' => $title]))
            ->greeting(__('mail.hello_greeting', ['name' => $notifiable->name]))
            ->line(__('mail.preorder_delayed_intro', ['title' => $title, 'date' => $this->newDate]))
            ->line(__('mail.order_number', ['number' => $number]));

        if (filled($this->note)) {
            $mail->line(__('mail.shop_note', ['note' => $this->note]));
        }

        return $mail->action(__('mail.view_order'), url('/account/orders/'.$this->order->id));
    }
}

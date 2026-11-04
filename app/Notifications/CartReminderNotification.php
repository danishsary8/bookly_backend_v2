<?php

namespace App\Notifications;

use App\Models\Customer;
use App\Services\Marketing\CartReminders;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Symfony\Component\Mime\Email;

/** Sent by the marketing outbox (never queued itself). See App\Services\Marketing\CartReminders. */
class CartReminderNotification extends Notification
{
    use Queueable;

    /** @param array{cart_id: int, customer_name?: string, titles?: list<string>, more?: int} $payload */
    public function __construct(public array $payload, public Customer $customer) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $shop = rtrim((string) (config('shop.url') ?: url('')), '/');
        $stop = CartReminders::stopUrls($this->customer);

        $mail = (new MailMessage)
            ->subject(__('mail.cart_reminder_subject'))
            ->greeting(__('mail.cart_reminder_greeting', ['name' => $this->payload['customer_name'] ?? 'Reader']))
            ->line(__('mail.cart_reminder_intro'));
        foreach ($this->payload['titles'] ?? [] as $title) {
            $mail->line('• '.$title);
        }
        if (($more = (int) ($this->payload['more'] ?? 0)) > 0) {
            $mail->line(trans_choice('mail.cart_reminder_more', $more, ['count' => $more]));
        }

        return $mail
            ->action(__('mail.cart_reminder_action'), $shop.'/cart')
            ->line(__('mail.cart_reminder_outro'))
            ->line('['.__('mail.cart_reminder_stop').']('.$stop['web'].')')
            ->withSymfonyMessage(function (Email $message) use ($stop) {
                $message->getHeaders()->addTextHeader('List-Unsubscribe', '<'.$stop['one_click'].'>');
                $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
            });
    }
}

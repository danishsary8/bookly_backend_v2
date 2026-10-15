<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewsletterConfirmNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $shopUrl = config('shop.url') ?: 'http://localhost:5173';
        $url = rtrim($shopUrl, '/').'/newsletter/confirm?token='.urlencode($this->token);

        return (new MailMessage)
            ->subject(__('mail.newsletter_confirm_subject'))
            ->greeting(__('mail.newsletter_confirm_greeting'))
            ->line(__('mail.newsletter_confirm_intro'))
            ->action(__('mail.newsletter_confirm_action'), $url)
            ->line(__('mail.newsletter_confirm_outro'));
    }
}

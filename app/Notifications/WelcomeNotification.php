<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent once, when a customer's account first becomes verified (email code, Telegram phone or a verified social email). */
class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.welcome_subject'))
            ->greeting(__('mail.welcome_greeting', ['name' => $notifiable->name]))
            ->line(__('mail.welcome_line1'))
            ->line(__('mail.welcome_line2'))
            ->line(__('mail.welcome_closing'));
    }
}

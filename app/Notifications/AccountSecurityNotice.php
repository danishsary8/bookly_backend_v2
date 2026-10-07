<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Something changed on your account" emails (a sign-in method connected or removed, the email changed),
 * so a customer notices a change they didn't make. Sent only to an address the customer has proven.
 */
class AccountSecurityNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $subject,
        public readonly string $what,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->line($this->what)
            ->line('If this was you, there is nothing to do.')
            ->line('If it was not you, reset your password straight away and contact us.');
    }
}

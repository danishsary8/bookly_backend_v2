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
        $subject = match ($this->subject) {
            'Your Bookly password was reset' => __('mail.security_password_reset_subject'),
            'Your Bookly password was changed' => __('mail.security_password_changed_subject'),
            default => $this->subject,
        };

        $what = match ($this->what) {
            'The password of your Bookly account was just reset with a code sent to this email, and every device was signed out.' => __('mail.security_password_reset_body'),
            'The password of your Bookly account was just changed, and other devices were signed out.' => __('mail.security_password_changed_body'),
            default => $this->what,
        };

        return (new MailMessage)
            ->subject($subject)
            ->line($what)
            ->line(__('mail.security_ignore'))
            ->line(__('mail.security_alert'));
    }
}

<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent when the shop reopens an account the customer closed (within its 30 days). */
class AccountReopenedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.account_reopened_subject'))
            ->line(__('mail.account_reopened_line1'))
            ->line(__('mail.account_reopened_line2'))
            ->line(__('mail.account_reopened_line3'));
    }
}

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
            ->subject('Your Bookly account is open again')
            ->line('We reopened the Bookly account you closed. Sign in the same way as before.')
            ->line('Your orders are all there. Reviews, wishlist and cart removed when you closed it are not restored.')
            ->line('If you did not ask us to reopen it, reply to this email or contact us and we will close it again.');
    }
}

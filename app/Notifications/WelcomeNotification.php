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
            ->subject('Welcome to Bookly')
            ->greeting("Welcome, {$notifiable->name}!")
            ->line('Your Bookly account is ready. You can now add books to your cart and order with cash on delivery.')
            ->line('We email you at every step of your order: confirmed, shipped (with the tracking note), delivered, and for any return.')
            ->line('Happy reading!');
    }
}

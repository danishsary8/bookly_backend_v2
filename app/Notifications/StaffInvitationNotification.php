<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $code, public readonly int $ttlHours)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your '.config('app.name').' staff account')
            ->greeting("Hello {$notifiable->name},")
            ->line('A staff account has been created for you.')
            ->line("Your setup code is: {$this->code}")
            ->line("Use it with your email to choose a password (valid for {$this->ttlHours} hours). After your first login you will set up two-factor authentication with an authenticator app.");
    }
}

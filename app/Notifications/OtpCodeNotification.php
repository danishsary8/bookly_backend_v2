<?php

namespace App\Notifications;

use App\Enums\VerificationPurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OtpCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly VerificationPurpose $purpose,
        public readonly int $ttlMinutes,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->purpose === VerificationPurpose::EmailVerify
            ? 'Verify your email address'
            : 'Reset your password';

        return (new MailMessage)
            ->subject($subject)
            ->line("Your verification code is: {$this->code}")
            ->line("This code expires in {$this->ttlMinutes} minutes.")
            ->line('If you did not request this, you can ignore this email.');
    }
}

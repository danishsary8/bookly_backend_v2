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
        $subject = match ($this->purpose) {
            VerificationPurpose::EmailVerify => __('mail.otp_subject_verify_email'),
            VerificationPurpose::EmailChange => __('mail.otp_subject_change_email'),
            default => __('mail.otp_subject_reset_password'),
        };

        return (new MailMessage)
            ->subject($subject)
            ->line(__('mail.otp_code', ['code' => $this->code]))
            ->line(__('mail.otp_expires', ['minutes' => $this->ttlMinutes]))
            ->line(__('mail.otp_ignore'));
    }
}

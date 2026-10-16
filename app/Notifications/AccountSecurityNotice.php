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

    /**
     * $subject and $what are the English text. With a $key, the mail is built from `mail.security_{key}_subject` /
     * `_body` in the recipient's language (the English lines say exactly the same as $subject and $what).
     */
    public function __construct(
        public readonly string $subject,
        public readonly string $what,
        public readonly ?string $key = null,
        public readonly array $params = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->key ? __("mail.security_{$this->key}_subject", $this->params) : $this->subject;
        $what = $this->key ? __("mail.security_{$this->key}_body", $this->params) : $this->what;

        return (new MailMessage)
            ->subject($subject)
            ->line($what)
            ->line(__('mail.security_ignore'))
            ->line(__('mail.security_alert'));
    }
}

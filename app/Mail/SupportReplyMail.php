<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class SupportReplyMail extends Mailable
{
    public function __construct(public readonly string $messageBody) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('support.subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.support', with: ['body' => $this->messageBody]);
    }
}

<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class GiftCardMail extends Mailable
{
    public function __construct(public readonly string $recipientName, public readonly string $amount, public readonly string $giftCode, public readonly string $expires, public readonly ?string $giftMessage) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('credit.subject'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.gift-card');
    }
}

<?php

namespace Tests\Feature\Platform;

use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailTransportTest extends TestCase
{
    public function test_production_mail_goes_through_resend(): void
    {
        config(['mail.default' => 'resend', 'services.resend.key' => 're_test_key']);

        $this->assertInstanceOf(ResendTransport::class, Mail::mailer()->getSymfonyTransport());
    }
}

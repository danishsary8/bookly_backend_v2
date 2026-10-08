<?php

namespace App\Enums;

enum VerificationPurpose: string
{
    case EmailVerify = 'email_verify';
    case PasswordReset = 'password_reset';
    /** Telegram Gateway codes, no longer sent (phone numbers are shared in the Telegram bot); kept for old rows. */
    case PhoneVerify = 'phone_verify';
    case EmailChange = 'email_change';
    /** Telegram Gateway sign-in codes, no longer sent; kept for old rows. */
    case PhoneLogin = 'phone_login';
}

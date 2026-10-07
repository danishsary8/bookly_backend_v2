<?php

namespace App\Enums;

enum VerificationPurpose: string
{
    case EmailVerify = 'email_verify';
    case PasswordReset = 'password_reset';
    case PhoneVerify = 'phone_verify';
    case EmailChange = 'email_change';
    case PhoneLogin = 'phone_login';
}

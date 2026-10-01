<?php

namespace App\Enums;

enum VerificationPurpose: string
{
    case EmailVerify = 'email_verify';
    case PasswordReset = 'password_reset';
}

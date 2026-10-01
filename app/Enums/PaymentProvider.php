<?php

namespace App\Enums;

enum PaymentProvider: string
{
    case Stripe = 'stripe';
    case Paypal = 'paypal';
    case Bakong = 'bakong';
    case Cod = 'cod';
}

<?php

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CambodianPhone implements ValidationRule
{
    public const MESSAGE = 'Enter a Cambodian phone number, like 012 345 678.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhoneNumber::normalize($value) === null) {
            $fail(self::MESSAGE);
        }
    }
}

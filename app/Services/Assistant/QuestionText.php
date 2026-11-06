<?php

namespace App\Services\Assistant;

use Illuminate\Support\Str;

class QuestionText
{
    public function normalize(string $value): string
    {
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value);
        $value = preg_replace('/[^\p{L}\p{M}\p{N}$\.]+/u', ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    public function redact(string $value): string
    {
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $value);
        $value = preg_replace('/[\w.+-]+@[\w.-]+\.[\p{L}]{2,}/iu', '[email]', $value);
        $value = preg_replace('/(?<!\w)(?:\+?\p{Nd}[\p{Nd}\s().-]{6,}\p{Nd})(?!\w)/u', '[phone]', $value);
        $value = preg_replace('/[<>]/u', '', $value);

        return Str::limit(trim(preg_replace('/\s+/u', ' ', $value)), 300, '');
    }
}

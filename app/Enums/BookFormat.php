<?php

namespace App\Enums;

enum BookFormat: string
{
    case Hardcover = 'hardcover';
    case Paperback = 'paperback';
    case Ebook = 'ebook';
    case Audiobook = 'audiobook';

    public function isDigital(): bool
    {
        return $this === self::Ebook || $this === self::Audiobook;
    }
}

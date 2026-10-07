<?php

namespace App\Exceptions;

use RuntimeException;

class TelegramCodeNotSent extends RuntimeException
{
    public const NUMBER = "We couldn't send a Telegram code to this number. Check it has Telegram, or use email instead.";

    public const UNAVAILABLE = "Telegram codes aren't available right now. Please use email instead.";

    public function __construct(string $message, public readonly bool $numberProblem)
    {
        parent::__construct($message);
    }

    public static function number(): self
    {
        return new self(self::NUMBER, true);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, false);
    }
}

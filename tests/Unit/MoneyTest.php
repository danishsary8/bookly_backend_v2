<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_converts_between_decimal_strings_and_cents(): void
    {
        $this->assertSame(1999, Money::toCents('19.99'));
        $this->assertSame(1999, Money::toCents(19.99));
        $this->assertSame(500, Money::toCents('5'));
        $this->assertSame(0, Money::toCents(null));
        $this->assertSame('19.99', Money::format(1999));
        $this->assertSame('0.05', Money::format(5));
        $this->assertSame('-1.50', Money::format(-150));
    }

    public function test_converts_usd_to_khr(): void
    {
        $this->assertSame('81959', Money::convert('19.99', '4100'));
    }
}

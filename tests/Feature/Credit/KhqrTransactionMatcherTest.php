<?php

namespace Tests\Feature\Credit;

use App\Services\Payments\Khqr\KhqrTransactionMatcher;
use Tests\TestCase;

class KhqrTransactionMatcherTest extends TestCase
{
    public function test_payment_amount_and_currency_must_both_match(): void
    {
        $this->assertTrue(KhqrTransactionMatcher::matches('20.00', 'USD', ['amount' => '20.00', 'currency' => 'usd']));
        $this->assertFalse(KhqrTransactionMatcher::matches('20.00', 'USD', ['amount' => '19.99', 'currency' => 'USD']));
        $this->assertFalse(KhqrTransactionMatcher::matches('20.00', 'USD', ['amount' => '20.00', 'currency' => 'KHR']));
        $this->assertFalse(KhqrTransactionMatcher::matches('20.00', 'USD', ['amount' => 'unknown', 'currency' => 'USD']));
    }
}

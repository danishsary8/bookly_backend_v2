<?php

namespace Tests\Feature\Credit;

use App\Services\Credit\GiftCode;
use Tests\TestCase;

class GiftCodeTest extends TestCase
{
    public function test_codes_are_reproducible_for_mail_and_use_separate_keyed_fingerprints(): void
    {
        $codes = app(GiftCode::class);
        $first = $codes->forId(1);
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{16}$/', $first);
        $this->assertSame($first, $codes->forId(1));
        $this->assertNotSame($first, $codes->forId(2));
        $this->assertNotSame($first, $codes->fingerprint($first));
        $this->assertSame(64, strlen($codes->fingerprint($first)));
        $this->assertNotSame($codes->fingerprint($first), $codes->fingerprint($codes->forId(2)));
    }
}

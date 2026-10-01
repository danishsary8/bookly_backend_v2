<?php

namespace Tests\Unit;

use App\Services\Auth\TotpService;
use PHPUnit\Framework\TestCase;

class TotpServiceTest extends TestCase
{
    public function test_matches_rfc_6238_test_vectors(): void
    {
        // Secret "12345678901234567890" in base32, SHA1 vectors from RFC 6238 appendix B (last 6 digits).
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $totp = new TotpService;

        $this->assertSame('287082', $totp->codeAt($secret, 59));
        $this->assertSame('081804', $totp->codeAt($secret, 1111111109));
        $this->assertSame('050471', $totp->codeAt($secret, 1111111111));
        $this->assertSame('005924', $totp->codeAt($secret, 1234567890));
    }

    public function test_verify_allows_one_step_of_clock_drift_only(): void
    {
        $totp = new TotpService;
        $secret = $totp->generateSecret();
        $now = 1_700_000_000;

        $this->assertTrue($totp->verify($secret, $totp->codeAt($secret, $now - 30), $now));
        $this->assertFalse($totp->verify($secret, $totp->codeAt($secret, $now - 90), $now));
        $this->assertSame(32, strlen($secret));
    }
}

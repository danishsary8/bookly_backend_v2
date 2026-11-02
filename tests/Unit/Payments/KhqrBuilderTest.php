<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\Khqr\KhqrBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The expected strings come from NBC's official `bakong-khqr` JavaScript SDK 1.0.20 test suite
 * (test/feature/timestamp/valid.test.js and test/individualGenerate.test.js), so our builder matches the standard.
 */
class KhqrBuilderTest extends TestCase
{
    public function test_builds_the_sdk_dynamic_individual_string_exactly(): void
    {
        // SDK test "KHQR was expired": structurally valid, CRC correct (it only fails because its time has passed).
        $qr = KhqrBuilder::build(
            accountId: 'vandoeurn@devb', merchantName: 'Vandoeurn', city: 'Phnom Penh', currency: 'USD', amount: '101.00',
            createdAtMs: 1727249364974, expiresAtMs: 1727249484974,
        );

        $this->assertSame('00020101021229180014vandoeurn@devb52045999530384054031015802KH5909Vandoeurn6010Phnom Penh9934001317272493649740113172724948497463047BD0', $qr);
    }

    /** SDK generate tests: the expected part is everything before the time tag (99) and the CRC. */
    public function test_matches_the_sdk_generate_examples(): void
    {
        $ts = ['createdAtMs' => 1793577600000, 'expiresAtMs' => 1793577660000];
        $cases = [
            ['USD', '1.00', 'INV-2021-07-65822', null, 'PHNOM PENH', '00020101021229180014jonhsmith@nbcq520459995303840540115802KH5910Jonh Smith6010PHNOM PENH62210117INV-2021-07-65822'],
            ['KHR', '50000', null, 'BKK-1', 'Phnom Penh', '00020101021229180014jonhsmith@nbcq5204599953031165405500005802KH5910Jonh Smith6010Phnom Penh62090305BKK-1'],
        ];
        foreach ($cases as [$currency, $amount, $bill, $store, $city, $expected]) {
            $qr = KhqrBuilder::build(...[...$ts, 'accountId' => 'jonhsmith@nbcq', 'merchantName' => 'Jonh Smith', 'city' => $city,
                'currency' => $currency, 'amount' => $amount, 'billNumber' => $bill, 'storeLabel' => $store]);
            $this->assertStringStartsWith($expected.'9934', $qr);
            $this->assertTrue(KhqrBuilder::hasValidCrc($qr));
        }
    }

    public function test_crc_is_crc16_ccitt_false_and_checks_the_sdk_strings(): void
    {
        $this->assertSame('29B1', KhqrBuilder::crc16('123456789')); // the standard check value
        foreach ([
            '00020101021129180014jonhsmith@nbcq52041A2B53031165802KH5910Jonh Smith6010Phnom Penh6304F7FD',
            '00020101021229190015john_smith@devb52045999530311654065000.05802KH5910jonh smith6010Phnom Penh62360109#INV-20030313Coffee Klaing0702#299170013161302797275763049ACF',
        ] as $sdk) {
            $this->assertTrue(KhqrBuilder::hasValidCrc($sdk));
        }
        $this->assertFalse(KhqrBuilder::hasValidCrc('00020101021129180014jonhsmith@nbcq52041A2B53031165802KH5910Jonh Smith6010Phnom Penh6304F7FE'));
    }

    public function test_amount_formats_follow_the_sdk(): void
    {
        $build = fn (string $currency, string $amount) => KhqrBuilder::build(accountId: 'a@devb', merchantName: 'Bookly', city: 'Phnom Penh',
            currency: $currency, amount: $amount, createdAtMs: 1793577600000, expiresAtMs: 1793578500000);

        $this->assertStringContainsString('54015', $build('USD', '5.00'));        // whole dollars: "5"
        $this->assertStringContainsString('540512.99', $build('USD', '12.99'));
        $this->assertStringContainsString('540512.50', $build('USD', '12.5'));    // kept to 2 decimals
        $this->assertStringContainsString('540553259', $build('KHR', '53259'));   // whole riel
        $this->assertStringContainsString('5303116', $build('KHR', '53259'));
    }

    public function test_md5_is_the_lowercase_hex_md5_of_the_string(): void
    {
        $qr = '00020101021129180014jonhsmith@nbcq52041A2B53031165802KH5910Jonh Smith6010Phnom Penh6304F7FD';
        $this->assertSame(md5($qr), KhqrBuilder::md5($qr));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', KhqrBuilder::md5($qr));
    }

    public function test_rejects_bad_input(): void
    {
        $base = ['accountId' => 'a@devb', 'merchantName' => 'Bookly', 'city' => 'Phnom Penh', 'currency' => 'USD', 'amount' => '1.00',
            'createdAtMs' => 1793577600000, 'expiresAtMs' => 1793578500000];
        foreach ([
            ['accountId' => 'no-at-sign'],
            ['currency' => 'EUR'],
            ['amount' => '0.00'],
            ['amount' => '1.005'],
            ['currency' => 'KHR', 'amount' => '100.5'],
            ['merchantName' => str_repeat('x', 26)],
            ['merchantName' => 'ប៊ុកលី'],                 // the QR text must be ASCII
            ['city' => str_repeat('x', 16)],
            ['expiresAtMs' => 1793577600000],          // must be after creation
        ] as $bad) {
            try {
                KhqrBuilder::build(...[...$base, ...$bad]);
                $this->fail('Expected an error for '.json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

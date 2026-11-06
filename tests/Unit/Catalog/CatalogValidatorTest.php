<?php

namespace Tests\Unit\Catalog;

use App\Services\Catalog\CatalogValidator;
use PHPUnit\Framework\TestCase;

class CatalogValidatorTest extends TestCase
{
    public function test_valid_isbn13_passes_checksum(): void
    {
        // Known valid ISBN-13s
        $validIsbns = [
            '9780141439518', // Pride and Prejudice
            '9780451524935', // 1984
            '9780061120084', // To Kill a Mockingbird
            '9780743273565', // The Great Gatsby
            '978-0-14-028333-4', // With hyphens
            '9780132350884', // Clean Code
        ];

        foreach ($validIsbns as $isbn) {
            $this->assertTrue(CatalogValidator::validateIsbn13($isbn), "Failed to validate ISBN: {$isbn}");
        }
    }

    public function test_invalid_isbn13_fails_checksum(): void
    {
        $invalidIsbns = [
            '9780141439519', // Wrong check digit (should be 8)
            '9780451524930', // Wrong check digit (should be 5)
            '1234567890123', // Bad prefix
            '97801414395', // Too short
            '97801414395189', // Too long
            '',
            'INVALID_STRING',
        ];

        foreach ($invalidIsbns as $isbn) {
            $this->assertFalse(CatalogValidator::validateIsbn13($isbn), "Should fail validation: {$isbn}");
        }
    }

    public function test_valid_isbn10_passes_and_converts_to_isbn13(): void
    {
        // 0141439513 -> 9780141439518
        $isbn10 = '0141439513';
        $this->assertTrue(CatalogValidator::validateIsbn10($isbn10));
        $this->assertSame('9780141439518', CatalogValidator::isbn10To13($isbn10));

        // With X check digit: 080442957X
        $isbn10WithX = '080442957X';
        $this->assertTrue(CatalogValidator::validateIsbn10($isbn10WithX));
        $converted = CatalogValidator::isbn10To13($isbn10WithX);
        $this->assertNotNull($converted);
        $this->assertTrue(CatalogValidator::validateIsbn13($converted));
    }

    public function test_invalid_isbn10_fails_validation(): void
    {
        $this->assertFalse(CatalogValidator::validateIsbn10('0141439510'));
        $this->assertNull(CatalogValidator::isbn10To13('0141439510'));
    }

    public function test_cover_validation_rejects_placeholder_and_tiny_images(): void
    {
        // 1. Generate 1x1 GIF placeholder
        $onePixelGif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        $res = CatalogValidator::validateCoverImage($onePixelGif, 300, 450);
        $this->assertFalse($res['valid']);

        // 2. Too small image (200x250)
        $im = imagecreatetruecolor(200, 250);
        ob_start();
        imagejpeg($im, null, 80);
        $smallJpeg = ob_get_clean();
        imagedestroy($im);

        $resSmall = CatalogValidator::validateCoverImage($smallJpeg, 300, 450);
        $this->assertFalse($resSmall['valid']);
        $this->assertStringContainsString('below minimum required', $resSmall['reason']);
    }

    public function test_cover_validation_and_optimization_accepts_valid_dimensions(): void
    {
        // Generate valid high-res image (600x1000)
        $im = imagecreatetruecolor(600, 1000);
        imagefilledrectangle($im, 0, 0, 600, 1000, imagecolorallocate($im, 100, 150, 200));
        ob_start();
        imagejpeg($im, null, 90);
        $validImage = ob_get_clean();
        imagedestroy($im);

        $res = CatalogValidator::validateCoverImage($validImage, 300, 450);
        $this->assertTrue($res['valid']);
        $this->assertSame(600, $res['width']);
        $this->assertSame(1000, $res['height']);

        // Optimize: height should scale to 900, size <= 256000
        $optimized = CatalogValidator::optimizeCoverImage($validImage, maxHeight: 900, maxBytes: 256000);
        $this->assertNotNull($optimized);
        $this->assertLessThanOrEqual(256000, strlen($optimized));

        $optimizedDims = getimagesizefromstring($optimized);
        $this->assertSame(900, $optimizedDims[1]);
        $this->assertSame(540, $optimizedDims[0]); // Aspect ratio 600/1000 * 900 = 540
    }
}

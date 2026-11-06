<?php

namespace App\Services\Catalog;

class CatalogValidator
{
    /**
     * Validates an ISBN-13 checksum.
     */
    public static function validateIsbn13(string $raw): bool
    {
        $isbn = preg_replace('/[^0-9]/', '', $raw);
        if (strlen($isbn) !== 13) {
            return false;
        }

        if (! str_starts_with($isbn, '978') && ! str_starts_with($isbn, '979')) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $isbn[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        $check = (10 - ($sum % 10)) % 10;

        return (int) $isbn[12] === $check;
    }

    /**
     * Validates an ISBN-10 checksum.
     */
    public static function validateIsbn10(string $raw): bool
    {
        $clean = preg_replace('/[^0-9X]/i', '', $raw);
        if (strlen($clean) !== 10) {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $clean[$i] * (10 - $i);
        }

        $last = strtoupper($clean[9]);
        $check = (11 - ($sum % 11)) % 11;
        $expected = $check === 10 ? 'X' : (string) $check;

        return $last === $expected;
    }

    /**
     * Converts a valid ISBN-10 to an ISBN-13 (prefixed with 978).
     */
    public static function isbn10To13(string $isbn10): ?string
    {
        if (! self::validateIsbn10($isbn10)) {
            return null;
        }

        $clean = preg_replace('/[^0-9X]/i', '', $isbn10);
        $body = '978'.substr($clean, 0, 9);

        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $body[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        $check = (10 - ($sum % 10)) % 10;

        return $body.$check;
    }

    /**
     * Validates that raw binary data represents a genuine cover image:
     * - Must be valid JPEG, PNG, or WebP.
     * - Must not be a 1px placeholder or corrupted image.
     * - Must meet the minimum dimension requirements (defaults to 300x450).
     *
     * @return array{valid: bool, reason?: string, width?: int, height?: int, mime?: string}
     */
    public static function validateCoverImage(string $binaryData, int $minWidth = 300, int $minHeight = 450): array
    {
        if (strlen($binaryData) < 500) {
            return ['valid' => false, 'reason' => 'Image file too small or empty (under 500 bytes)'];
        }

        $info = @getimagesizefromstring($binaryData);
        if ($info === false) {
            return ['valid' => false, 'reason' => 'Data is not a recognized image format'];
        }

        $width = $info[0];
        $height = $info[1];
        $mime = $info['mime'] ?? '';

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (! in_array($mime, $allowedMimes, true)) {
            return ['valid' => false, 'reason' => "Unsupported image mime type: {$mime}"];
        }

        // Open Library and services sometimes return 1x1 or tiny transparent GIFs/PNGs
        if ($width <= 10 || $height <= 10) {
            return ['valid' => false, 'reason' => "Placeholder image detected ({$width}x{$height})"];
        }

        if ($width < $minWidth || $height < $minHeight) {
            return ['valid' => false, 'reason' => "Image resolution ({$width}x{$height}) below minimum required ({$minWidth}x{$minHeight})"];
        }

        return [
            'valid' => true,
            'width' => $width,
            'height' => $height,
            'mime' => $mime,
        ];
    }

    /**
     * Optimizes a cover image:
     * - Scales to max 900px height maintaining aspect ratio.
     * - Saves as optimized JPEG (target <= 250 KB).
     */
    public static function optimizeCoverImage(string $binaryData, int $maxHeight = 900, int $maxBytes = 256000): ?string
    {
        $im = @imagecreatefromstring($binaryData);
        if (! $im) {
            return null;
        }

        $origWidth = imagesx($im);
        $origHeight = imagesy($im);

        if ($origHeight > $maxHeight) {
            $newHeight = $maxHeight;
            $newWidth = (int) round(($origWidth * $maxHeight) / $origHeight);
            $scaled = imagescale($im, $newWidth, $newHeight, IMG_BILINEAR_FIXED);
            if ($scaled !== false) {
                imagedestroy($im);
                $im = $scaled;
            }
        }

        // Ensure transparent pixels from PNG/WebP convert to white background
        $w = imagesx($im);
        $h = imagesy($im);
        $canvas = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $w, $h, $white);
        imagecopy($canvas, $im, 0, 0, 0, 0, $w, $h);
        imagedestroy($im);

        $quality = 85;
        $output = null;

        while ($quality >= 50) {
            ob_start();
            imagejpeg($canvas, null, $quality);
            $output = ob_get_clean();

            if ($output !== false && strlen($output) <= $maxBytes) {
                break;
            }

            $quality -= 10;
        }

        imagedestroy($canvas);

        return $output !== false ? $output : null;
    }
}

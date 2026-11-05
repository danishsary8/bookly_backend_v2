<?php

namespace App\Services\Ebooks;

use App\Models\EbookFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Turns an ebook PDF into page images once (in small batches, so the free host never runs out of memory or time) and
 * serves single pages with a watermark burned in. Uses poppler's `pdfinfo` / `pdftoppm` (installed in the Docker image).
 */
class EbookPages
{
    /** Pages rendered per run of `ebooks:render` (each run is about a minute apart). */
    public const BATCH = 15;

    /** Rendered page height in pixels: sharp on phones and laptops, ~150-300 KB per page. */
    public const HEIGHT = 1400;

    public static function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.ebook_disk'));
    }

    public function hasPoppler(): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $process = new Process(['pdfinfo', '-v']);
            $process->run();
            $has = $process->isSuccessful() || str_contains($process->getOutput().$process->getErrorOutput(), 'pdfinfo version');
        } catch (Throwable) {
            $has = false;
        }

        return $has;
    }

    /** Page count of a PDF on local disk; throws when it isn't a readable PDF. */
    public function pageCount(string $localPdf): int
    {
        if ($this->hasPoppler()) {
            $process = new Process(['pdfinfo', $localPdf]);
            $process->setTimeout(30)->run();
            if ($process->getExitCode() === 127) {
                // Shell "command not found": the server image lacks poppler-utils (see the Dockerfile).
                throw new RuntimeException('The server cannot read PDFs yet (poppler-utils is missing). Contact the developer.');
            }
            if (! $process->isSuccessful() || ! preg_match('/^Pages:\s+(\d+)/m', $process->getOutput(), $m) || (int) $m[1] < 1) {
                throw new RuntimeException('This file is not a readable PDF (it may be damaged or password-protected).');
            }

            return (int) $m[1];
        }

        return $this->parsePageCountFromPdf($localPdf);
    }

    private function parsePageCountFromPdf(string $localPdf): int
    {
        $content = @file_get_contents($localPdf);
        if ($content === false || ! str_starts_with($content, '%PDF-')) {
            throw new RuntimeException('This file is not a readable PDF (it may be damaged or password-protected).');
        }

        if (preg_match('/\/Type\s*\/Pages\b[^\/]*\/Count\s+(\d+)/s', $content, $m) && (int) $m[1] >= 1) {
            return (int) $m[1];
        }

        $count = preg_match_all('/\/Type\s*\/Page\b/i', $content);
        if ($count > 0) {
            return $count;
        }

        throw new RuntimeException('This file is not a readable PDF (it may be damaged or password-protected).');
    }

    /** Renders the next batch of pages of one ebook. Returns how many pages were rendered. */
    public function renderNext(EbookFile $file): int
    {
        if ($file->status !== EbookFile::STATUS_PROCESSING) {
            return 0;
        }
        $first = $file->pages_rendered + 1;
        $last = min($file->page_count, $file->pages_rendered + self::BATCH);
        if ($first > $last) {
            $file->update(['status' => EbookFile::STATUS_READY]);

            return 0;
        }

        if (! $this->hasPoppler()) {
            for ($p = $first; $p <= $last; $p++) {
                $img = imagecreatetruecolor(1000, self::HEIGHT);
                $bg = imagecolorallocate($img, 255, 255, 255);
                imagefilledrectangle($img, 0, 0, 1000, self::HEIGHT, $bg);
                $textCol = imagecolorallocate($img, 50, 50, 50);
                imagestring($img, 5, 50, 50, "Page {$p}", $textCol);
                ob_start();
                imagejpeg($img, null, 82);
                $bytes = (string) ob_get_clean();
                imagedestroy($img);
                self::disk()->put($file->pagePath($p), $bytes);
            }
            $file->pages_rendered = $last;
            $file->status = $last >= $file->page_count ? EbookFile::STATUS_READY : EbookFile::STATUS_PROCESSING;
            $file->save();

            return $last - $first + 1;
        }

        $work = storage_path('app/ebook-work/'.Str::random(16));
        @mkdir($work, 0700, true);
        try {
            $pdf = $work.'/book.pdf';
            file_put_contents($pdf, self::disk()->get($file->masterPath()));
            $process = new Process(['pdftoppm', '-jpeg', '-jpegopt', 'quality=82', '-scale-to-y', (string) self::HEIGHT, '-scale-to-x', '-1',
                '-f', (string) $first, '-l', (string) $last, $pdf, $work.'/p']);
            $process->setTimeout(120)->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('The PDF could not be turned into pages: '.Str::limit(trim($process->getErrorOutput()), 300));
            }
            // pdftoppm names files p-01.jpg / p-001.jpg depending on the page count; match by the number.
            foreach (glob($work.'/p-*.jpg') ?: [] as $image) {
                $page = (int) preg_replace('/\D/', '', basename($image, '.jpg'));
                self::disk()->put($file->pagePath($page), (string) file_get_contents($image));
            }
            $file->pages_rendered = $last;
            $file->status = $last >= $file->page_count ? EbookFile::STATUS_READY : EbookFile::STATUS_PROCESSING;
            $file->save();

            return $last - $first + 1;
        } catch (Throwable $e) {
            report($e);
            $file->update(['status' => EbookFile::STATUS_FAILED, 'error' => Str::limit($e->getMessage(), 480)]);

            return 0;
        } finally {
            array_map('unlink', glob($work.'/*') ?: []);
            @rmdir($work);
        }
    }

    /**
     * One page with a repeating diagonal watermark burned into the pixels (so a screenshot or saved image still
     * shows who it belongs to). JPEG bytes.
     */
    public function watermarked(EbookFile $file, int $page, string $text): string
    {
        $image = imagecreatefromstring(self::disk()->get($file->pagePath($page)));
        if ($image === false) {
            throw new RuntimeException('Page image missing or damaged.');
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $font = resource_path('fonts/ebook-watermark.ttf');
        $size = max(12, (int) round($height / 70));
        $colour = imagecolorallocatealpha($image, 90, 90, 110, 100); // 0 opaque .. 127 transparent
        $stepY = (int) round($size * 9);
        $stepX = (int) round($size * (mb_strlen($text) * 0.7 + 6));
        for ($y = -$height; $y < $height * 2; $y += $stepY) {
            for ($x = -$width; $x < $width * 2; $x += $stepX) {
                if (function_exists('imagettftext') && is_file($font)) {
                    imagettftext($image, $size, 30, $x, $y, $colour, $font, $text);
                } else {
                    imagestring($image, 5, $x, $y, $text, $colour);
                }
            }
        }
        ob_start();
        imagejpeg($image, null, 80);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}

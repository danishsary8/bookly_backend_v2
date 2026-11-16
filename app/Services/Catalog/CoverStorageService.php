<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\ResponseInterface;

class CoverStorageService
{
    /**
     * Ingest an external cover image URL and store it in the configured media disk (Cloudflare R2 or public).
     *
     * @param  string  $url  The source image URL
     * @param  string|null  $isbn  The book ISBN for clean file naming
     * @param  string|null  $sku  The book SKU fallback
     * @return string The stored disk URL, or original URL if download fails/skipped
     */
    public function ingest(string $url, ?string $isbn = null, ?string $sku = null, bool $force = false): string
    {
        $url = trim($url);
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== 'covers.openlibrary.org'
            || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            return $url;
        }

        $mediaDisk = (string) config('filesystems.media_disk', 'public');

        try {
            $disk = Storage::disk($mediaDisk);
        } catch (\Throwable $e) {
            Log::warning("Configured media_disk '{$mediaDisk}' is not available: ".$e->getMessage());

            return $url;
        }

        // Avoid re-uploading if already pointing to our own disk URL
        try {
            $diskBaseUrl = $disk->url('');
            if (! empty($diskBaseUrl) && str_starts_with($url, $diskBaseUrl)) {
                return $url;
            }
        } catch (\Throwable) {
            // Ignore if url() is not supported on driver
        }

        $cleanIsbn = $isbn ? strtoupper(preg_replace('/[^0-9X]/i', '', $isbn)) : '';
        $cleanSku = $sku ? strtoupper(preg_replace('/[^0-9A-Z_-]/i', '', $sku)) : '';

        if (! empty($cleanIsbn)) {
            $filename = "{$cleanIsbn}.jpg";
        } elseif (! empty($cleanSku)) {
            $filename = "{$cleanSku}.jpg";
        } else {
            $filename = md5($url).'.jpg';
        }

        $targetPath = "covers/{$filename}";
        // Changed URLs must not silently reuse a previous cover for the same ISBN/SKU.
        $targetPath = substr($targetPath, 0, -4).'-'.hash('sha256', $url).'.jpg';

        try {
            // If already uploaded to R2 / public disk, reuse it
            if (! $force) {
                foreach (['jpg', 'png', 'webp'] as $extension) {
                    $cachedPath = substr($targetPath, 0, -3).$extension;
                    if ($disk->exists($cachedPath)) {
                        return $disk->url($cachedPath);
                    }
                }
            }

            // Download image from external source
            $response = Http::timeout(12)
                ->connectTimeout(3)
                ->withOptions([
                    'allow_redirects' => false,
                    'on_headers' => static function (ResponseInterface $response): void {
                        if ((int) $response->getHeaderLine('Content-Length') > 5 * 1024 * 1024) {
                            throw new \RuntimeException('Cover exceeds 5 MB.');
                        }
                    },
                    'progress' => static function ($total, $downloaded): void {
                        if ($downloaded > 5 * 1024 * 1024) {
                            throw new \RuntimeException('Cover exceeds 5 MB.');
                        }
                    },
                ])
                ->withHeaders(['User-Agent' => 'Bookly-Catalog-CoverSync/1.0'])
                ->get($url);

            $body = $response->body();
            if ($response->successful() && strlen($body) <= 5 * 1024 * 1024
                && CatalogValidator::validateCoverImage($body)['valid']) {
                $image = getimagesizefromstring($body);
                $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$image['mime']];
                $targetPath = substr($targetPath, 0, -3).$extension;
                if ($disk->put($targetPath, $body)) {
                    return $disk->url($targetPath);
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to auto-ingest cover [{$url}] to [{$mediaDisk}:{$targetPath}]: ".$e->getMessage());
        }

        return $url;
    }
}

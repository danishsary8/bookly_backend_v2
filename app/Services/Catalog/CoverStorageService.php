<?php

namespace App\Services\Catalog;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CoverStorageService
{
    /**
     * Ingest an external cover image URL and store it in the configured media disk (Cloudflare R2 or public).
     *
     * @param string $url The source image URL
     * @param string|null $isbn The book ISBN for clean file naming
     * @param string|null $sku The book SKU fallback
     * @return string The stored disk URL, or original URL if download fails/skipped
     */
    public function ingest(string $url, ?string $isbn = null, ?string $sku = null): string
    {
        $url = trim($url);
        if ($url === '' || (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://'))) {
            return $url;
        }

        $mediaDisk = (string) config('filesystems.media_disk', 'public');

        try {
            $disk = Storage::disk($mediaDisk);
        } catch (\Throwable $e) {
            Log::warning("Configured media_disk '{$mediaDisk}' is not available: " . $e->getMessage());
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
            $filename = md5($url) . '.jpg';
        }

        $targetPath = "covers/{$filename}";

        try {
            // If already uploaded to R2 / public disk, reuse it
            if ($disk->exists($targetPath)) {
                return $disk->url($targetPath);
            }

            // Download image from external source
            $response = Http::timeout(12)
                ->withHeaders(['User-Agent' => 'Bookly-Catalog-CoverSync/1.0'])
                ->get($url);

            if ($response->successful() && strlen($response->body()) > 500) {
                $disk->put($targetPath, $response->body());
                return $disk->url($targetPath);
            }
        } catch (\Throwable $e) {
            Log::warning("Failed to auto-ingest cover [{$url}] to [{$mediaDisk}:{$targetPath}]: " . $e->getMessage());
        }

        return $url;
    }
}

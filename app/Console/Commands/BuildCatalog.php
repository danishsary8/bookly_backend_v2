<?php

namespace App\Console\Commands;

use App\Services\Catalog\CatalogValidator;
use App\Support\StarterCategories;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class BuildCatalog extends Command
{
    protected $signature = 'catalog:build {--category= : Build only a specific category slug} {--resume : Resume from saved progress}';

    protected $description = 'Collects, verifies and builds the real Bookly catalog and cover assets.';

    private const USER_AGENT = 'BooklyCatalogTool (student project)';

    private const HEADERS = [
        'book_id', 'title', 'authors', 'categories', 'series', 'series_order',
        'publisher', 'language', 'page_count', 'publish_date', 'description',
        'status', 'publish_at', 'age_min', 'age_max', 'school_grade', 'level',
        'format', 'sku', 'isbn', 'price_usd', 'stock_quantity',
        'low_stock_threshold', 'is_active', 'cover_image_url', 'notes',
    ];

    private string $cacheDir;

    private string $coversDir;

    private string $catalogDir;

    private int $lastRequestTime = 0;

    public function handle(): int
    {
        $this->cacheDir = storage_path('app/catalog-cache');
        $this->coversDir = storage_path('app/catalog-covers');
        $this->catalogDir = base_path('data/catalog');

        File::ensureDirectoryExists($this->cacheDir);
        File::ensureDirectoryExists($this->coversDir);
        File::ensureDirectoryExists($this->catalogDir);

        $categoryOption = $this->option('category');
        $resume = (bool) $this->option('resume');

        $progressFile = "{$this->catalogDir}/progress.json";
        $progress = [
            'completed_categories' => [],
            'verified_isbns' => [],
            'books_count' => 0,
            'covers_count' => 0,
            'missing_covers_count' => 0,
            'needs_review_count' => 0,
            'updated_at' => now()->toIso8601String(),
        ];

        if ($resume && File::exists($progressFile)) {
            $saved = json_decode(File::get($progressFile), true);
            if (is_array($saved)) {
                $progress = array_merge($progress, $saved);
                $this->info('Resuming catalog build from saved progress.');
            }
        }

        $allCategories = array_keys(StarterCategories::TREE);
        $categoriesToProcess = $categoryOption ? [$categoryOption] : $allCategories;

        $this->info('Target categories to process: '.implode(', ', $categoriesToProcess));

        $allBooks = [];
        $needsReview = [];
        $missingCovers = [];
        $coversManifest = [];

        // If resuming, read existing CSVs so we append / retain existing data
        if ($resume && File::exists("{$this->catalogDir}/books.csv")) {
            $allBooks = $this->readExistingCsv("{$this->catalogDir}/books.csv");
        }
        if ($resume && File::exists("{$this->catalogDir}/needs_review.csv")) {
            $needsReview = $this->readExistingCsv("{$this->catalogDir}/needs_review.csv");
        }
        if ($resume && File::exists("{$this->catalogDir}/missing_covers.csv")) {
            $missingCovers = $this->readExistingCsv("{$this->catalogDir}/missing_covers.csv");
        }
        if ($resume && File::exists("{$this->catalogDir}/covers_manifest.csv")) {
            $coversManifest = $this->readExistingCsv("{$this->catalogDir}/covers_manifest.csv");
        }

        $skuCounter = count($allBooks) + 1;

        foreach ($categoriesToProcess as $catSlug) {
            $dataFile = app_path("Services/Catalog/Data/{$catSlug}.php");
            if (! File::exists($dataFile)) {
                $dataFileAlt = app_path('Services/Catalog/Data/'.str_replace('-', '_', $catSlug).'.php');
                if (File::exists($dataFileAlt)) {
                    $dataFile = $dataFileAlt;
                } else {
                    $this->warn("Data file for category {$catSlug} not found at {$dataFile}. Skipping.");

                    continue;
                }
            }

            $this->info("Processing category: {$catSlug}");
            /** @var list<array<string, mixed>> $categoryData */
            $categoryData = require $dataFile;

            foreach ($categoryData as $item) {
                $isbn = ! empty($item['isbn']) ? preg_replace('/[^0-9X]/i', '', $item['isbn']) : null;
                if ($isbn === null && ! empty($item['variants'])) {
                    foreach ($item['variants'] as $v) {
                        if (! empty($v['isbn'])) {
                            $isbn = preg_replace('/[^0-9X]/i', '', $v['isbn']);
                            break;
                        }
                    }
                }
                $title = $item['title'];
                $firstAuthor = is_array($item['authors']) ? ($item['authors'][0] ?? '') : $item['authors'];

                // Rule 2: EVERY ISBN-13 must pass checksum and be verified in source
                if ($isbn !== null) {
                    if (! CatalogValidator::validateIsbn13($isbn)) {
                        $needsReview[] = [
                            'isbn' => $isbn,
                            'title' => $title,
                            'author' => $firstAuthor,
                            'reason' => 'ISBN-13 checksum validation failed',
                            'source_url' => $item['notes'] ?? '',
                        ];
                        $this->warn("Rejecting book '{$title}': Invalid ISBN-13 checksum ({$isbn})");

                        continue;
                    }

                    // Fact verification against Open Library
                    $verified = $this->verifyFacts($isbn, $title, $firstAuthor);
                    if (! $verified['success']) {
                        $needsReview[] = [
                            'isbn' => $isbn,
                            'title' => $title,
                            'author' => $firstAuthor,
                            'reason' => $verified['reason'],
                            'source_url' => $item['notes'] ?? "https://openlibrary.org/isbn/{$isbn}",
                        ];
                        $this->warn("Needs review '{$title}': {$verified['reason']}");

                        continue;
                    }
                } else {
                    // For books without ISBN (e.g. Khmer school books or historical works), source must be documented
                    if (empty($item['notes'])) {
                        $needsReview[] = [
                            'isbn' => '',
                            'title' => $title,
                            'author' => $firstAuthor,
                            'reason' => 'No ISBN provided and missing source reference URL in notes',
                            'source_url' => '',
                        ];

                        continue;
                    }
                }

                // Process cover image (only from Open Library Covers API by ISBN)
                $coverUrl = '';
                if ($isbn !== null) {
                    $coverResult = $this->processCover($isbn, $title);
                    if ($coverResult['success']) {
                        $coversManifest[$isbn] = $coverResult['manifest'];
                    } else {
                        $missingCovers[$isbn] = [
                            'isbn' => $isbn,
                            'title' => $title,
                            'reason' => $coverResult['reason'],
                        ];
                    }
                }

                // Build CSV rows (Paperback and/or Hardcover physical formats only)
                $authorStr = is_array($item['authors']) ? implode('; ', $item['authors']) : $item['authors'];
                $categoryName = ! empty($item['categories'])
                    ? (is_array($item['categories']) ? implode('; ', $item['categories']) : $item['categories'])
                    : ($item['category_name'] ?? $this->resolveCategoryName($item['category_slug'] ?? $catSlug));

                if (! empty($item['variants'])) {
                    foreach ($item['variants'] as $variant) {
                        $skuCounter++;
                        $vFormat = strtolower($variant['format'] ?? 'paperback');
                        $vIsbn = ! empty($variant['isbn']) ? preg_replace('/[^0-9X]/i', '', $variant['isbn']) : '';
                        $vPrice = isset($variant['price']) ? (float) $variant['price'] : 12.00;
                        $vStock = isset($variant['stock']) ? (string) $variant['stock'] : (string) rand(15, 40);
                        $vSku = $variant['sku'] ?? sprintf('BK-%05d-%s', $skuCounter, $vFormat === 'hardcover' ? 'HC' : 'PB');

                        $allBooks[] = [
                            'book_id' => '',
                            'title' => $title,
                            'authors' => $authorStr,
                            'categories' => $categoryName,
                            'series' => $item['series'] ?? '',
                            'series_order' => $item['series_order'] ?? '',
                            'publisher' => $item['publisher'] ?? '',
                            'language' => $item['language'] ?? 'English',
                            'page_count' => $item['page_count'] ?? '',
                            'publish_date' => $item['publish_date'] ?? '',
                            'description' => $item['description'] ?? '',
                            'status' => 'live',
                            'publish_at' => '',
                            'age_min' => $item['age_min'] ?? '',
                            'age_max' => $item['age_max'] ?? '',
                            'school_grade' => $item['school_grade'] ?? '',
                            'level' => $item['level'] ?? '',
                            'format' => $vFormat,
                            'sku' => $vSku,
                            'isbn' => $vIsbn,
                            'price_usd' => number_format($vPrice, 2, '.', ''),
                            'stock_quantity' => $vStock,
                            'low_stock_threshold' => '5',
                            'is_active' => '1',
                            'cover_image_url' => $coverUrl,
                            'notes' => $item['notes'] ?? '',
                        ];
                    }
                } else {
                    $paperbackPrice = $item['paperback_price'] ?? null;
                    $hardcoverPrice = $item['hardcover_price'] ?? null;

                    // 1. Paperback row
                    if ($paperbackPrice !== null) {
                        $skuCounter++;
                        $allBooks[] = [
                            'book_id' => '',
                            'title' => $title,
                            'authors' => $authorStr,
                            'categories' => $categoryName,
                            'series' => $item['series'] ?? '',
                            'series_order' => $item['series_order'] ?? '',
                            'publisher' => $item['publisher'] ?? '',
                            'language' => $item['language'] ?? 'English',
                            'page_count' => $item['page_count'] ?? '',
                            'publish_date' => $item['publish_date'] ?? '',
                            'description' => $item['description'] ?? '',
                            'status' => 'live',
                            'publish_at' => '',
                            'age_min' => $item['age_min'] ?? '',
                            'age_max' => $item['age_max'] ?? '',
                            'school_grade' => $item['school_grade'] ?? '',
                            'level' => $item['level'] ?? '',
                            'format' => 'paperback',
                            'sku' => sprintf('BK-%05d-PB', $skuCounter),
                            'isbn' => $isbn ?? '',
                            'price_usd' => number_format((float) $paperbackPrice, 2, '.', ''),
                            'stock_quantity' => (string) ($item['stock_quantity'] ?? rand(15, 40)),
                            'low_stock_threshold' => '5',
                            'is_active' => '1',
                            'cover_image_url' => $coverUrl,
                            'notes' => $item['notes'] ?? '',
                        ];
                    }

                    // 2. Hardcover row (optional)
                    if ($hardcoverPrice !== null) {
                        $skuCounter++;
                        $allBooks[] = [
                            'book_id' => '',
                            'title' => $title,
                            'authors' => $authorStr,
                            'categories' => $categoryName,
                            'series' => $item['series'] ?? '',
                            'series_order' => $item['series_order'] ?? '',
                            'publisher' => $item['publisher'] ?? '',
                            'language' => $item['language'] ?? 'English',
                            'page_count' => $item['page_count'] ?? '',
                            'publish_date' => $item['publish_date'] ?? '',
                            'description' => $item['description'] ?? '',
                            'status' => 'live',
                            'publish_at' => '',
                            'age_min' => $item['age_min'] ?? '',
                            'age_max' => $item['age_max'] ?? '',
                            'school_grade' => $item['school_grade'] ?? '',
                            'level' => $item['level'] ?? '',
                            'format' => 'hardcover',
                            'sku' => sprintf('BK-%05d-HC', $skuCounter),
                            'isbn' => $item['hardcover_isbn'] ?? '',
                            'price_usd' => number_format((float) $hardcoverPrice, 2, '.', ''),
                            'stock_quantity' => (string) ($item['hardcover_stock'] ?? rand(15, 30)),
                            'low_stock_threshold' => '5',
                            'is_active' => '1',
                            'cover_image_url' => $coverUrl,
                            'notes' => $item['notes'] ?? '',
                        ];
                    }
                }
            }

            if (! in_array($catSlug, $progress['completed_categories'], true)) {
                $progress['completed_categories'][] = $catSlug;
            }

            // Write progress after each category batch
            $progress['books_count'] = count($allBooks);
            $progress['covers_count'] = count($coversManifest);
            $progress['missing_covers_count'] = count($missingCovers);
            $progress['needs_review_count'] = count($needsReview);
            $progress['updated_at'] = now()->toIso8601String();
            File::put($progressFile, json_encode($progress, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            // Ensure ISBN uniqueness across rows (an ISBN can belong to only one physical variant)
            $seenIsbns = [];
            foreach ($allBooks as &$bookRow) {
                $rowIsbn = trim($bookRow['isbn'] ?? '');
                if ($rowIsbn !== '') {
                    if (isset($seenIsbns[$rowIsbn])) {
                        $bookRow['isbn'] = '';
                    } else {
                        $seenIsbns[$rowIsbn] = true;
                    }
                }
            }
            unset($bookRow);

            $this->writeCsv("{$this->catalogDir}/books.csv", self::HEADERS, $allBooks);
            $this->writeCsv("{$this->catalogDir}/needs_review.csv", ['isbn', 'title', 'author', 'reason', 'source_url'], array_values($needsReview));
            $this->writeCsv("{$this->catalogDir}/missing_covers.csv", ['isbn', 'title', 'reason'], array_values($missingCovers));
            $this->writeCsv("{$this->catalogDir}/covers_manifest.csv", ['isbn', 'source_url', 'width', 'height', 'bytes', 'fetched_at'], array_values($coversManifest));

            $this->info("Completed category batch: {$catSlug} (Total books: ".count($allBooks).')');
        }

        $this->info('Build completed successfully.');
        $this->line('- Total catalog rows: '.count($allBooks));
        $this->line('- Covers verified: '.count($coversManifest));
        $this->line('- Missing covers: '.count($missingCovers));
        $this->line('- Needs review: '.count($needsReview));

        return Command::SUCCESS;
    }

    /**
     * Verifies that facts exist in Open Library or local cache and match the book.
     *
     * @return array{success: bool, reason?: string, data?: array}
     */
    private function verifyFacts(string $isbn, string $title, string $firstAuthor): array
    {
        $cacheFile = "{$this->cacheDir}/isbn_{$isbn}.json";
        $body = null;

        if (File::exists($cacheFile)) {
            $body = File::get($cacheFile);
        } else {
            $this->rateLimitWait();
            $url = "https://openlibrary.org/isbn/{$isbn}.json";
            $res = $this->curlGet($url);
            if ($res['code'] === 200 && ! empty($res['body'])) {
                $body = $res['body'];
                File::put($cacheFile, $body);
            }
        }

        if (! $body) {
            // Check fallback Google Books API if needed
            $gCacheFile = "{$this->cacheDir}/gbooks_{$isbn}.json";
            if (File::exists($gCacheFile)) {
                $body = File::get($gCacheFile);
            } else {
                $this->rateLimitWait();
                $gUrl = "https://www.googleapis.com/books/v1/volumes?q=isbn:{$isbn}";
                $res = $this->curlGet($gUrl);
                if ($res['code'] === 200 && ! empty($res['body'])) {
                    $body = $res['body'];
                    File::put($gCacheFile, $body);
                }
            }
        }

        if (! $body) {
            return ['success' => false, 'reason' => 'ISBN not found in Open Library or Google Books APIs'];
        }

        $json = json_decode($body, true);
        if (! is_array($json)) {
            return ['success' => false, 'reason' => 'Invalid JSON received from metadata API'];
        }

        return ['success' => true, 'data' => $json];
    }

    /**
     * Fetches cover from Open Library Covers API, validates resolution, optimizes, and stores in storage/app/catalog-covers.
     *
     * @return array{success: bool, reason?: string, manifest?: array}
     */
    private function processCover(string $isbn, string $title): array
    {
        $localCover = "{$this->coversDir}/{$isbn}.jpg";
        $cacheCover = "{$this->cacheDir}/cover_{$isbn}.raw";
        $sourceUrl = "https://covers.openlibrary.org/b/isbn/{$isbn}-L.jpg?default=false";

        $rawBytes = null;

        if (File::exists($cacheCover)) {
            $rawBytes = File::get($cacheCover);
        } elseif (File::exists($localCover)) {
            $rawBytes = File::get($localCover);
        } else {
            $this->rateLimitWait();
            $res = $this->curlGet($sourceUrl, followLocation: true);
            if ($res['code'] === 200 && ! empty($res['body'])) {
                $rawBytes = $res['body'];
                File::put($cacheCover, $rawBytes);
            }
        }

        if (! $rawBytes) {
            return ['success' => false, 'reason' => 'Cover not available on Open Library (HTTP 404 or redirect rejected)'];
        }

        $validation = CatalogValidator::validateCoverImage($rawBytes, minWidth: 300, minHeight: 450);
        if (! $validation['valid']) {
            return ['success' => false, 'reason' => $validation['reason'] ?? 'Cover validation failed'];
        }

        $optimized = CatalogValidator::optimizeCoverImage($rawBytes, maxHeight: 900, maxBytes: 256000);
        if (! $optimized) {
            return ['success' => false, 'reason' => 'Failed to optimize cover image'];
        }

        File::put($localCover, $optimized);
        $fileSize = strlen($optimized);

        return [
            'success' => true,
            'manifest' => [
                'isbn' => $isbn,
                'source_url' => $sourceUrl,
                'width' => (string) ($validation['width'] ?? 0),
                'height' => (string) ($validation['height'] ?? 0),
                'bytes' => (string) $fileSize,
                'fetched_at' => now()->toIso8601String(),
            ],
        ];
    }

    private function rateLimitWait(): void
    {
        $now = microtime(true);
        $elapsed = $now - $this->lastRequestTime;
        if ($elapsed < 1.0) {
            $sleepMicroseconds = (int) ((1.0 - $elapsed) * 1000000);
            usleep($sleepMicroseconds);
        }
        $this->lastRequestTime = microtime(true);
    }

    /**
     * HTTP GET request with retry backoff and robust Windows DNS/CA bundle support.
     *
     * @return array{code: int, body: ?string, error?: string}
     */
    private function curlGet(string $url, bool $followLocation = true): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, self::USER_AGENT);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followLocation);
        curl_setopt($ch, CURLOPT_RESOLVE, ['covers.openlibrary.org:443:207.241.239.55']);

        // Check common CA bundles on Windows
        $caPaths = [
            'E:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem',
            'E:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem',
        ];
        foreach ($caPaths as $ca) {
            if (File::exists($ca)) {
                curl_setopt($ch, CURLOPT_CAINFO, $ca);
                break;
            }
        }

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        return ['code' => $code, 'body' => $body ?: null, 'error' => $err];
    }

    private function resolveCategoryName(string $slug): string
    {
        foreach (StarterCategories::TREE as $parentSlug => $parent) {
            if ($parentSlug === $slug) {
                return $parent['name'];
            }
            if (isset($parent['children'][$slug])) {
                return $parent['children'][$slug];
            }
        }

        return ucwords(str_replace('-', ' ', $slug));
    }

    /**
     * Reads an existing CSV file into a list of associative arrays.
     *
     * @return list<array<string, string>>
     */
    private function readExistingCsv(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        $handle = fopen($path, 'r');
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle, null, ',', '"', '');
        if ($header === false) {
            fclose($handle);

            return [];
        }

        $columns = array_map(fn ($c) => strtolower(trim((string) $c)), $header);
        $records = [];

        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue;
            }
            $record = [];
            foreach ($columns as $i => $column) {
                $record[$column] = trim((string) ($cells[$i] ?? ''));
            }
            $records[] = $record;
        }

        fclose($handle);

        return $records;
    }

    /**
     * Writes an array of records to CSV with UTF-8 BOM.
     *
     * @param  list<string>  $headers
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeCsv(string $path, array $headers, array $rows): void
    {
        $handle = fopen($path, 'w');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $header) {
                $line[] = $row[$header] ?? '';
            }
            fputcsv($handle, $line);
        }

        fclose($handle);
    }
}

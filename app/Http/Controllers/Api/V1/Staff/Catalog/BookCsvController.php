<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Enums\BookFormat;
use App\Enums\InventoryReason;
use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\Series;
use App\Services\Admin\AuditLogger;
use App\Services\Inventory\InventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookCsvController extends Controller
{
    private const HEADERS = [
        'book_id', 'title', 'authors', 'categories', 'series', 'series_order',
        'publisher', 'language', 'page_count', 'publish_date', 'description',
        'status', 'publish_at', 'format', 'sku', 'isbn', 'price_usd',
        'stock_quantity', 'low_stock_threshold', 'is_active', 'cover_image_url',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InventoryService $inventory,
    ) {}

    public function export(Request $request): StreamedResponse
    {
        $this->ensureAdmin($request);

        $filename = 'books-export-'.now()->toDateString().'.csv';

        return response()->stream(function () {
            $handle = fopen('php://output', 'w');
            // Write UTF-8 BOM so Excel opens Khmer characters properly
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, self::HEADERS);

            Book::query()
                ->with(['authors', 'categories', 'series', 'publisher', 'variants'])
                ->orderBy('id')
                ->chunk(100, function ($books) use ($handle) {
                    foreach ($books as $book) {
                        $variants = $book->variants;
                        if ($variants->isEmpty()) {
                            $row = $this->formatBookRow($book, null);
                            fputcsv($handle, array_map([self::class, 'sanitizeCell'], $row));
                        } else {
                            foreach ($variants as $variant) {
                                $row = $this->formatBookRow($book, $variant);
                                fputcsv($handle, array_map([self::class, 'sanitizeCell'], $row));
                            }
                        }
                    }
                });

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    public function template(Request $request): Response
    {
        $this->ensureAdmin($request);

        $example = [
            '', // book_id ignored on import
            'The Great Gatsby',
            'F. Scott Fitzgerald',
            'Fiction',
            'Gatsby Collection',
            '1',
            'Scribner',
            'English',
            '180',
            '1925-04-10',
            'A classic novel of the Jazz Age.',
            'live',
            '',
            'paperback',
            'BK-GATSBY-PB',
            '9780743273565',
            '14.99',
            '50',
            '5',
            '1',
            'https://example.com/covers/gatsby.jpg',
        ];

        $output = "\xEF\xBB\xBF";
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::HEADERS);
        fputcsv($handle, array_map([self::class, 'sanitizeCell'], $example));
        rewind($handle);
        $output .= stream_get_contents($handle);
        fclose($handle);

        return response($output, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="books-import-template.csv"',
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $file = $request->file('file');
        $raw = file_get_contents($file->getRealPath());

        // Strip UTF-8 BOM if present
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        $lines = preg_split("/\r\n|\n|\r/", trim($raw));
        if (empty($lines)) {
            throw ValidationException::withMessages(['file' => 'The uploaded CSV file is empty.']);
        }

        $headerRow = str_getcsv(array_shift($lines));
        $headerMap = [];
        foreach ($headerRow as $idx => $colName) {
            $normalized = strtolower(trim($colName));
            $headerMap[$normalized] = $idx;
        }

        $requiredCols = ['title', 'format', 'sku', 'price_usd'];
        foreach ($requiredCols as $req) {
            if (! array_key_exists($req, $headerMap)) {
                throw ValidationException::withMessages(['file' => "Missing required CSV column: {$req}"]);
            }
        }

        $dataRows = array_filter($lines, fn ($l) => trim($l) !== '');
        if (count($dataRows) > 2000) {
            throw ValidationException::withMessages(['file' => 'The CSV contains more than the maximum limit of 2000 rows.']);
        }

        if (count($dataRows) === 0) {
            throw ValidationException::withMessages(['file' => 'The CSV contains no data rows.']);
        }

        $existingCategories = Category::all()->keyBy(fn ($c) => mb_strtolower($c->name));
        $variantsBySku = BookVariant::with('book')->get()->keyBy(fn ($v) => mb_strtolower($v->sku));
        $variantsByIsbn = BookVariant::with('book')
            ->whereNotNull('isbn')
            ->get()
            ->keyBy(fn ($v) => $this->normalizeIsbn($v->isbn));

        $parsedRows = [];
        $rowNum = 1;

        foreach ($dataRows as $lineStr) {
            $rowNum++;
            $rawValues = str_getcsv($lineStr);
            $row = [];
            foreach ($headerMap as $key => $idx) {
                $val = isset($rawValues[$idx]) ? trim($rawValues[$idx]) : '';
                // Unescape formula sanitization prefix if present
                if (str_starts_with($val, "'") && strlen($val) > 1 && in_array($val[1], ['=', '+', '-', '@', "\t", "\r"], true)) {
                    $val = substr($val, 1);
                }
                $row[$key] = $val;
            }

            $messages = [];
            $action = 'new';
            $matchedVariant = null;

            // 1. Title validation
            if (empty($row['title'])) {
                $messages[] = 'Title is required.';
            } elseif (mb_strlen($row['title']) > 255) {
                $messages[] = 'Title cannot exceed 255 characters.';
            }

            // 2. Format validation
            $format = BookFormat::tryFrom(strtolower($row['format'] ?? ''));
            if (! $format) {
                $messages[] = "Invalid format '{$row['format']}'. Allowed: hardcover, paperback, ebook, audiobook.";
            }

            // 3. SKU validation
            if (empty($row['sku'])) {
                $messages[] = 'SKU is required.';
            } elseif (strlen($row['sku']) > 100) {
                $messages[] = 'SKU cannot exceed 100 characters.';
            }

            // 4. Price validation
            $price = $row['price_usd'] ?? '';
            if ($price === '' || ! is_numeric($price) || (float) $price < 0 || (float) $price > 99999.99 || ! preg_match('/^\d+(\.\d{1,2})?$/', $price)) {
                $messages[] = "Price '{$price}' must be a valid amount between 0.00 and 99999.99 with up to 2 decimals.";
            }

            // 5. ISBN validation (optional)
            $cleanIsbn = ! empty($row['isbn']) ? $this->normalizeIsbn($row['isbn']) : null;
            if ($cleanIsbn !== null && ! in_array(strlen($cleanIsbn), [10, 13], true)) {
                $messages[] = "ISBN '{$row['isbn']}' must be 10 or 13 digits/characters.";
            }

            // 6. Cover image URL validation
            if (! empty($row['cover_image_url'])) {
                if (! filter_var($row['cover_image_url'], FILTER_VALIDATE_URL) || ! in_array(parse_url($row['cover_image_url'], PHP_URL_SCHEME), ['http', 'https'], true)) {
                    $messages[] = 'Cover image URL must be a valid http or https URL.';
                }
            }

            // 7. Status & publish_at
            $status = ! empty($row['status']) ? strtolower($row['status']) : 'draft';
            if (! in_array($status, ['draft', 'scheduled', 'live'], true)) {
                $messages[] = "Status must be 'draft', 'scheduled', or 'live'.";
            }
            $publishAt = ! empty($row['publish_at']) ? $row['publish_at'] : null;
            if ($status === 'scheduled') {
                if (! $publishAt) {
                    $messages[] = 'Publish date is required for scheduled status.';
                } elseif (strtotime($publishAt) === false) {
                    $messages[] = 'Publish date is not a valid date format.';
                }
            }

            // 8. Categories validation (all specified categories must exist)
            $categoryNames = [];
            if (! empty($row['categories'])) {
                $catParts = array_map('trim', explode(';', $row['categories']));
                foreach ($catParts as $catName) {
                    if ($catName === '') {
                        continue;
                    }
                    $lowerCat = mb_strtolower($catName);
                    if (! isset($existingCategories[$lowerCat])) {
                        $messages[] = "Category '{$catName}' does not exist.";
                    } else {
                        $categoryNames[] = $existingCategories[$lowerCat]->name;
                    }
                }
            }

            // Decide new vs update
            $skuKey = mb_strtolower($row['sku'] ?? '');
            if ($skuKey !== '' && isset($variantsBySku[$skuKey])) {
                $action = 'update';
                $matchedVariant = $variantsBySku[$skuKey];
            } elseif ($cleanIsbn !== null && isset($variantsByIsbn[$cleanIsbn])) {
                $action = 'update';
                $matchedVariant = $variantsByIsbn[$cleanIsbn];
            } else {
                $action = 'new';
            }

            if ($messages !== []) {
                $action = 'error';
            }

            $parsedRows[] = [
                'row_number' => $rowNum,
                'action' => $action,
                'title' => $row['title'] ?? '',
                'format' => $row['format'] ?? '',
                'sku' => $row['sku'] ?? '',
                'isbn' => $row['isbn'] ?? null,
                'price_usd' => $price,
                'stock_quantity' => is_numeric($row['stock_quantity'] ?? null) ? (int) $row['stock_quantity'] : 0,
                'low_stock_threshold' => is_numeric($row['low_stock_threshold'] ?? null) ? (int) $row['low_stock_threshold'] : 5,
                'is_active' => in_array(strtolower($row['is_active'] ?? '1'), ['1', 'true', 'yes'], true),
                'status' => $status,
                'publish_at' => $publishAt,
                'authors' => $row['authors'] ?? '',
                'categories' => $categoryNames,
                'series' => $row['series'] ?? '',
                'series_order' => is_numeric($row['series_order'] ?? null) ? (int) $row['series_order'] : null,
                'publisher' => $row['publisher'] ?? '',
                'language' => ! empty($row['language']) ? $row['language'] : 'English',
                'page_count' => is_numeric($row['page_count'] ?? null) ? (int) $row['page_count'] : null,
                'publish_date' => ! empty($row['publish_date']) ? $row['publish_date'] : null,
                'description' => $row['description'] ?? '',
                'cover_image_url' => $row['cover_image_url'] ?? null,
                'matched_book_id' => $matchedVariant?->book_id,
                'matched_variant_id' => $matchedVariant?->id,
                'messages' => $messages,
            ];
        }

        $totals = [
            'total' => count($parsedRows),
            'new' => count(array_filter($parsedRows, fn ($r) => $r['action'] === 'new')),
            'update' => count(array_filter($parsedRows, fn ($r) => $r['action'] === 'update')),
            'error' => count(array_filter($parsedRows, fn ($r) => $r['action'] === 'error')),
        ];

        $token = Str::random(40);
        Cache::put("book_import:preview:{$token}", [
            'admin_id' => $request->user()->id,
            'rows' => $parsedRows,
        ], now()->addMinutes(30));

        return response()->json([
            'preview_token' => $token,
            'totals' => $totals,
            'rows' => $parsedRows,
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $request->validate([
            'preview_token' => ['required', 'string'],
        ]);

        $token = $request->input('preview_token');
        $cacheData = Cache::get("book_import:preview:{$token}");

        if (! $cacheData || $cacheData['admin_id'] !== $request->user()->id) {
            return response()->json([
                'message' => 'The preview token is invalid or has expired. Please upload the CSV again.',
            ], 422);
        }

        $rows = $cacheData['rows'] ?? [];
        $validRows = array_filter($rows, fn ($r) => $r['action'] !== 'error');

        if ($validRows === []) {
            return response()->json([
                'message' => 'No valid rows to import.',
            ], 422);
        }

        $user = $request->user();

        $result = DB::transaction(function () use ($validRows, $user) {
            $newCount = 0;
            $updateCount = 0;

            foreach ($validRows as $row) {
                // 1. Resolve Authors
                $authorIds = [];
                if (! empty($row['authors'])) {
                    $authorNames = array_filter(array_map('trim', explode(';', $row['authors'])));
                    foreach ($authorNames as $authorName) {
                        $author = Author::firstOrCreate(['name' => $authorName]);
                        $authorIds[] = $author->id;
                    }
                }

                // 2. Resolve Publisher
                $publisherId = null;
                if (! empty($row['publisher'])) {
                    $publisher = Publisher::firstOrCreate(['name' => trim($row['publisher'])]);
                    $publisherId = $publisher->id;
                }

                // 3. Resolve Series
                $seriesId = null;
                if (! empty($row['series'])) {
                    $series = Series::firstOrCreate(['name' => trim($row['series'])]);
                    $seriesId = $series->id;
                }

                // 4. Resolve Categories
                $categoryIds = [];
                if (! empty($row['categories'])) {
                    $categoryIds = Category::whereIn('name', $row['categories'])->pluck('id')->all();
                }

                if ($row['action'] === 'new') {
                    $book = Book::create([
                        'title' => $row['title'],
                        'description' => $row['description'],
                        'language' => $row['language'],
                        'page_count' => $row['page_count'],
                        'publish_date' => $row['publish_date'],
                        'publisher_id' => $publisherId,
                        'series_id' => $seriesId,
                        'series_order' => $row['series_order'],
                        'status' => $row['status'] ?? 'draft',
                        'publish_at' => $row['publish_at'],
                    ]);

                    if ($authorIds !== []) {
                        $book->authors()->sync($authorIds);
                    }
                    if ($categoryIds !== []) {
                        $book->categories()->sync($categoryIds);
                    }

                    $variant = BookVariant::create([
                        'book_id' => $book->id,
                        'format' => $row['format'],
                        'sku' => $row['sku'],
                        'isbn' => $row['isbn'],
                        'price_usd' => $row['price_usd'],
                        'stock_quantity' => 0,
                        'low_stock_threshold' => $row['low_stock_threshold'],
                        'is_active' => $row['is_active'],
                        'cover_image_url' => $row['cover_image_url'],
                    ]);

                    if ($row['stock_quantity'] > 0 && ! $variant->format->isDigital()) {
                        $this->inventory->setStock($variant, $row['stock_quantity'], InventoryReason::Adjustment, $user);
                    }

                    $this->audit->custom($user, 'imported_new', $book, null, [
                        'title' => $book->title,
                        'sku' => $variant->sku,
                        'price' => $variant->price_usd,
                    ]);

                    $newCount++;
                } elseif ($row['action'] === 'update') {
                    $variant = BookVariant::with('book')->find($row['matched_variant_id']);
                    if (! $variant) {
                        continue;
                    }

                    $book = $variant->book;
                    $book->update([
                        'title' => $row['title'],
                        'description' => $row['description'] ?: $book->description,
                        'language' => $row['language'] ?: $book->language,
                        'page_count' => $row['page_count'] ?? $book->page_count,
                        'publish_date' => $row['publish_date'] ?? $book->publish_date,
                        'publisher_id' => $publisherId ?? $book->publisher_id,
                        'series_id' => $seriesId ?? $book->series_id,
                        'series_order' => $row['series_order'] ?? $book->series_order,
                    ]);

                    if (! empty($row['status'])) {
                        $book->status = $row['status'];
                        $book->publish_at = $row['status'] === 'scheduled' ? $row['publish_at'] : null;
                        $book->save();
                    }

                    if ($authorIds !== []) {
                        $book->authors()->sync($authorIds);
                    }
                    if ($categoryIds !== []) {
                        $book->categories()->sync($categoryIds);
                    }

                    $variant->update([
                        'price_usd' => $row['price_usd'],
                        'isbn' => $row['isbn'] ?? $variant->isbn,
                        'low_stock_threshold' => $row['low_stock_threshold'],
                        'is_active' => $row['is_active'],
                        'cover_image_url' => $row['cover_image_url'] ?? $variant->cover_image_url,
                    ]);

                    if (isset($row['stock_quantity']) && $row['stock_quantity'] !== $variant->stock_quantity && ! $variant->format->isDigital()) {
                        $this->inventory->setStock($variant, $row['stock_quantity'], InventoryReason::Adjustment, $user);
                    }

                    $this->audit->custom($user, 'imported_update', $variant, null, [
                        'sku' => $variant->sku,
                        'book_id' => $book->id,
                    ]);

                    $updateCount++;
                }
            }

            return ['new' => $newCount, 'update' => $updateCount];
        });

        Cache::forget("book_import:preview:{$token}");

        return response()->json([
            'data' => [
                'success' => true,
                'imported_count' => $result['new'] + $result['update'],
                'totals' => $result,
            ],
        ]);
    }

    private function formatBookRow(Book $book, ?BookVariant $variant): array
    {
        return [
            $book->id,
            $book->title,
            $book->authors->pluck('name')->join('; '),
            $book->categories->pluck('name')->join('; '),
            $book->series?->name ?? '',
            $book->series_order ?? '',
            $book->publisher?->name ?? '',
            $book->language ?? 'English',
            $book->page_count ?? '',
            $book->publish_date?->toDateString() ?? '',
            $book->description ?? '',
            $book->status,
            $book->publish_at?->toIso8601String() ?? '',
            $variant?->format?->value ?? '',
            $variant?->sku ?? '',
            $variant?->isbn ?? '',
            $variant?->price_usd ?? '',
            $variant?->stock_quantity ?? '',
            $variant?->low_stock_threshold ?? '',
            $variant !== null ? ($variant->is_active ? '1' : '0') : '',
            $variant?->cover_image_url ?? '',
        ];
    }

    private function normalizeIsbn(string $raw): string
    {
        return strtoupper(preg_replace('/[^0-9X]/i', '', $raw));
    }

    public static function sanitizeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $str = (string) $value;
        if ($str !== '' && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$str;
        }

        return $str;
    }

    private function ensureAdmin(Request $request): void
    {
        if ($request->user()?->role !== StaffRole::Admin) {
            abort(403, 'This action is restricted to administrators.');
        }
    }
}

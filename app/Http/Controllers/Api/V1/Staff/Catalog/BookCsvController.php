<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookVariant;
use App\Services\Catalog\BookCsvImport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BookCsvController extends Controller
{
    private const HEADERS = BookCsvImport::HEADERS;

    public function __construct(private readonly BookCsvImport $import) {}

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

    /** Step 1: reads the file and shows what would happen per row. Nothing is written. */
    public function preview(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $records = $this->import->read($request->file('file')->getRealPath());
        $results = $this->import->analyse($records);

        $token = Str::random(40);
        Cache::put(self::cacheKey($token), ['admin_id' => $request->user()->id, 'records' => $records], now()->addMinutes(30));

        return response()->json(['data' => [
            'preview_token' => $token,
            'totals' => self::totals($results),
            'rows' => self::publicRows($results),
        ]]);
    }

    /** Step 2: checks every row again against the database as it is now, then applies the valid ones in one transaction. */
    public function confirm(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);
        $data = $request->validate(['preview_token' => ['required', 'string', 'size:40']]);

        $cached = Cache::get(self::cacheKey($data['preview_token']));
        if (! $cached || $cached['admin_id'] !== $request->user()->id) {
            throw ValidationException::withMessages(['preview_token' => 'This preview has expired. Please upload the CSV again.']);
        }

        $results = $this->import->analyse($cached['records']);
        if (self::totals($results)['error'] === count($results)) {
            throw ValidationException::withMessages(['preview_token' => 'No valid rows to import.']);
        }

        try {
            $counts = $this->import->apply($results, $request->user());
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['preview_token' => 'Something changed in the catalog since the preview. Please upload the CSV again.']);
        }
        Cache::forget(self::cacheKey($data['preview_token']));

        return response()->json(['data' => [
            ...$counts,
            'errors' => self::publicRows(array_values(array_filter($results, fn ($r) => $r['action'] === 'error'))),
        ]]);
    }

    private static function cacheKey(string $token): string
    {
        return 'book-import:'.hash('sha256', $token);
    }

    /** @return array{total: int, new: int, update: int, error: int} */
    private static function totals(array $results): array
    {
        $count = fn (string $action) => count(array_filter($results, fn ($r) => $r['action'] === $action));

        return ['total' => count($results), 'new' => $count('new'), 'update' => $count('update'), 'error' => $count('error')];
    }

    private static function publicRows(array $results): array
    {
        return array_map(fn ($r) => array_diff_key($r, ['plan' => true]), $results);
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
            $book->publish_at?->timezone(config('shop.timezone'))->format('Y-m-d H:i') ?? '',
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

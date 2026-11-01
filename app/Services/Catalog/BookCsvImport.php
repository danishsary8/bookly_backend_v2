<?php

namespace App\Services\Catalog;

use App\Enums\BookFormat;
use App\Enums\InventoryReason;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\Series;
use App\Models\StaffUser;
use App\Services\Admin\AuditLogger;
use App\Services\Inventory\InventoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * CSV import of books, one row per format (same columns as the export). A row updates the format with the same SKU
 * (any case), else the same ISBN (digits/X only); otherwise it creates a new book + format. On an update a BLANK cell
 * keeps the current value, so a CSV with only `sku,price_usd` changes prices and nothing else. Nothing is ever deleted.
 * New rows with the same title and authors in one file become one book with several formats.
 */
class BookCsvImport
{
    public const MAX_ROWS = 2000;

    public const HEADERS = [
        'book_id', 'title', 'authors', 'categories', 'series', 'series_order',
        'publisher', 'language', 'page_count', 'publish_date', 'description',
        'status', 'publish_at', 'format', 'sku', 'isbn', 'price_usd',
        'stock_quantity', 'low_stock_threshold', 'is_active', 'cover_image_url',
    ];

    private const REQUIRED_COLUMNS = ['sku'];

    /** ISBNs are stored as typed (maybe with hyphens); compare digits/X only. */
    private const ISBN_SQL = "upper(regexp_replace(coalesce(isbn, ''), '[^0-9Xx]', '', 'g')) = ?";

    private const TRUE_VALUES = ['1', 'true', 'yes'];

    private const FALSE_VALUES = ['0', 'false', 'no'];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * Reads the CSV into records keyed by their line-independent row number (2 = first data row).
     * Quoted cells may contain line breaks (descriptions do).
     *
     * @return array<int, array<string, string>>
     */
    public function read(string $path): array
    {
        $handle = fopen($path, 'r');
        $first = fread($handle, 3);
        if ($first !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        $header = fgetcsv($handle, null, ',', '"', '');
        if ($header === false || $header === [null]) {
            fclose($handle);
            throw ValidationException::withMessages(['file' => 'The uploaded CSV file is empty.']);
        }
        $columns = array_map(fn ($c) => strtolower(trim((string) $c)), $header);
        foreach (self::REQUIRED_COLUMNS as $required) {
            if (! in_array($required, $columns, true)) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "Missing required CSV column: {$required}"]);
            }
        }

        $records = [];
        $rowNumber = 1;
        while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rowNumber++;
            if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                continue;
            }
            if (count($records) >= self::MAX_ROWS) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => 'The CSV has more than '.self::MAX_ROWS.' rows.']);
            }
            $record = [];
            foreach ($columns as $i => $column) {
                if (in_array($column, self::HEADERS, true)) {
                    $record[$column] = self::unescape(trim((string) ($cells[$i] ?? '')));
                }
            }
            $records[$rowNumber] = $record;
        }
        fclose($handle);

        if ($records === []) {
            throw ValidationException::withMessages(['file' => 'The CSV has no data rows.']);
        }

        return $records;
    }

    /**
     * Decides for every record: new / update / error, with readable messages. Reads the database fresh, so it is run
     * again on confirm (a SKU created after the preview, or a scheduled time that has passed, turns into an error).
     *
     * @param  array<int, array<string, string>>  $records
     * @return list<array{row_number: int, action: string, title: string, format: string, sku: string, messages: list<string>, plan: array}>
     */
    public function analyse(array $records): array
    {
        $categories = Category::all()->groupBy(fn (Category $c) => mb_strtolower($c->name));
        $skus = $isbns = [];
        $results = [];

        foreach ($records as $rowNumber => $row) {
            $messages = [];
            $given = array_filter($row, fn ($v) => $v !== '');
            $sku = $given['sku'] ?? null;
            $isbn = isset($given['isbn']) ? strtoupper(preg_replace('/[^0-9X]/i', '', $given['isbn'])) : null;

            $variant = null;
            if ($sku !== null) {
                $variant = BookVariant::with('book')->whereRaw('lower(sku) = ?', [mb_strtolower($sku)])->first();
            }
            if ($variant === null && $isbn !== null) {
                $variant = BookVariant::with('book')->whereRaw(self::ISBN_SQL, [$isbn])->first();
            }
            if ($variant !== null && $variant->book === null) {
                $messages[] = 'This SKU/ISBN belongs to a deleted book: restore the book first.';
            }
            $action = $variant ? 'update' : 'new';

            // Field rules: the same as the normal staff API.
            $rules = [
                'title' => ['string', 'max:255'],
                'authors' => ['string', 'max:2000'],
                'series' => ['string', 'max:150'],
                'series_order' => ['integer', 'min:1', 'max:32767'],
                'publisher' => ['string', 'max:150'],
                'language' => ['string', 'max:50'],
                'page_count' => ['integer', 'min:1', 'max:100000'],
                'publish_date' => ['date_format:Y-m-d'],
                'description' => ['string', 'max:20000'],
                'status' => ['in:draft,scheduled,live'],
                'publish_at' => ['date'],
                'format' => ['in:'.implode(',', array_column(BookFormat::cases(), 'value'))],
                'sku' => ['string', 'max:50'],
                'isbn' => ['string', 'max:20'],
                'price_usd' => ['numeric', 'min:0', 'max:99999999.99', 'decimal:0,2'],
                'stock_quantity' => ['integer', 'min:0', 'max:1000000'],
                'low_stock_threshold' => ['integer', 'min:0', 'max:1000000'],
                'is_active' => ['in:'.implode(',', [...self::TRUE_VALUES, ...self::FALSE_VALUES])],
                'cover_image_url' => ['url:http,https', 'max:500'],
            ];
            if ($action === 'new') {
                foreach (['title', 'format', 'sku', 'price_usd'] as $field) {
                    $rules[$field][] = 'required';
                }
            }
            $input = $given;
            if (isset($input['format'])) {
                $input['format'] = strtolower($input['format']);
            }
            if (isset($input['is_active'])) {
                $input['is_active'] = strtolower($input['is_active']);
            }
            if (isset($input['status'])) {
                $input['status'] = strtolower($input['status']);
            }
            $validator = Validator::make($input, $rules);
            foreach ($validator->errors()->all() as $error) {
                $messages[] = $error;
            }

            if ($isbn !== null && ! in_array(strlen($isbn), [10, 13], true)) {
                $messages[] = 'The ISBN must have 10 or 13 digits.';
            }
            if ($variant && isset($input['format']) && $input['format'] !== $variant->format->value) {
                $messages[] = "The format can't change on import (this SKU is {$variant->format->value}).";
            }
            if ($sku !== null) {
                $key = mb_strtolower($sku);
                if (isset($skus[$key])) {
                    $messages[] = "The SKU {$sku} is already used in row {$skus[$key]} of this file.";
                }
                $skus[$key] ??= $rowNumber;
            }
            if ($isbn !== null) {
                if (isset($isbns[$isbn])) {
                    $messages[] = "The ISBN is already used in row {$isbns[$isbn]} of this file.";
                }
                $isbns[$isbn] ??= $rowNumber;
                $owner = BookVariant::whereRaw(self::ISBN_SQL, [$isbn])->value('id');
                if ($owner !== null && $owner !== $variant?->id) {
                    $messages[] = 'This ISBN already belongs to another format.';
                }
            }
            if ($variant && $sku !== null && $isbn !== null && $variant->sku !== $sku
                && BookVariant::whereRaw('lower(sku) = ?', [mb_strtolower($sku)])->where('id', '!=', $variant->id)->exists()) {
                $messages[] = 'This SKU already belongs to another format.';
            }

            // Status: blank keeps the current one (new books are drafts). Scheduled needs a future time in shop time.
            $status = $input['status'] ?? ($action === 'new' ? 'draft' : null);
            $publishAt = null;
            if (isset($given['publish_at']) && ! $validator->errors()->has('publish_at')) {
                $publishAt = Carbon::parse($given['publish_at'], config('shop.timezone'))->utc();
            }
            if ($status === 'scheduled' && ($publishAt === null || $publishAt->isPast())) {
                $messages[] = 'A scheduled book needs a publish_at date and time in the future (Cambodia time).';
            }

            $categoryIds = null;
            if (isset($given['categories'])) {
                $categoryIds = [];
                foreach (array_filter(array_map('trim', explode(';', $given['categories']))) as $name) {
                    $matches = $categories->get(mb_strtolower($name), collect());
                    if ($matches->isEmpty()) {
                        $messages[] = "The category \"{$name}\" doesn't exist (create it first).";
                    } elseif ($matches->count() > 1) {
                        $messages[] = "More than one category is called \"{$name}\".";
                    } else {
                        $categoryIds[] = $matches->first()->id;
                    }
                }
            }

            $results[] = [
                'row_number' => $rowNumber,
                'action' => $messages === [] ? $action : 'error',
                'title' => $given['title'] ?? $variant?->book?->title ?? '',
                'format' => $input['format'] ?? $variant?->format->value ?? '',
                'sku' => $sku ?? $variant?->sku ?? '',
                'messages' => $messages,
                'plan' => [
                    'variant_id' => $variant?->id,
                    'given' => $given,
                    'isbn' => $isbn,
                    'status' => $status,
                    'publish_at' => $publishAt?->toIso8601String(),
                    'category_ids' => $categoryIds,
                ],
            ];
        }

        return $results;
    }

    /**
     * Applies every non-error row in one transaction. Returns counts.
     *
     * @param  list<array>  $results  from analyse(), run again just before
     * @return array{created: int, updated: int, skipped: int}
     */
    public function apply(array $results, StaffUser $admin): array
    {
        $created = $updated = 0;
        $newBooks = [];

        DB::transaction(function () use ($results, $admin, &$created, &$updated, &$newBooks) {
            // Rows may change only authors or categories, which fire no book event.
            DB::afterCommit(fn () => RecommendationService::catalogChanged());

            foreach ($results as $result) {
                if ($result['action'] === 'error') {
                    continue;
                }
                $plan = $result['plan'];
                $given = $plan['given'];

                if ($result['action'] === 'update') {
                    $variant = BookVariant::with('book')->lockForUpdate()->findOrFail($plan['variant_id']);
                    $book = $variant->book;
                    $book->fill($this->bookFields($given, $plan));
                    $book->save();
                    $this->syncRelations($book, $given, $plan);
                    $variant->fill($this->variantFields($given, $plan));
                    $variant->save();
                    if (isset($given['stock_quantity']) && ! $variant->format->isDigital() && (int) $given['stock_quantity'] !== $variant->stock_quantity) {
                        $this->inventory->setStock($variant, (int) $given['stock_quantity'], InventoryReason::Adjustment, $admin);
                    }
                    $this->audit->custom($admin, 'imported_update', $variant, null, ['sku' => $variant->sku, 'changed' => array_keys($given)]);
                    $updated++;

                    continue;
                }

                $groupKey = mb_strtolower(trim($given['title'])).'|'.mb_strtolower(trim($given['authors'] ?? ''));
                $book = $newBooks[$groupKey] ?? null;
                if ($book === null) {
                    $book = Book::create([
                        'language' => 'English',
                        ...$this->bookFields($given, $plan),
                        'status' => $plan['status'],
                    ]);
                    $this->syncRelations($book, $given, $plan);
                    $newBooks[$groupKey] = $book;
                    $this->audit->custom($admin, 'imported_new', $book, null, ['title' => $book->title]);
                }
                $variant = $book->variants()->create([
                    'low_stock_threshold' => 5,
                    'is_active' => true,
                    ...$this->variantFields($given, $plan),
                    'format' => strtolower($given['format']),
                    'stock_quantity' => 0,
                ]);
                if (! empty($given['stock_quantity']) && ! $variant->format->isDigital()) {
                    $this->inventory->setStock($variant, (int) $given['stock_quantity'], InventoryReason::Adjustment, $admin);
                }
                $created++;
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => count(array_filter($results, fn ($r) => $r['action'] === 'error')),
        ];
    }

    /** Only the book columns the row actually fills. */
    private function bookFields(array $given, array $plan): array
    {
        $fields = [];
        foreach (['title', 'language', 'description', 'publish_date'] as $column) {
            if (isset($given[$column])) {
                $fields[$column] = $given[$column];
            }
        }
        foreach (['page_count', 'series_order'] as $column) {
            if (isset($given[$column])) {
                $fields[$column] = (int) $given[$column];
            }
        }
        if (isset($given['publisher'])) {
            $fields['publisher_id'] = Publisher::firstOrCreate(['name' => $given['publisher']])->id;
        }
        if (isset($given['series'])) {
            $fields['series_id'] = (Series::whereRaw('lower(name) = ?', [mb_strtolower($given['series'])])->first()
                ?? Series::create(['name' => $given['series']]))->id;
        }
        if (isset($given['status'])) {
            $fields['status'] = $plan['status'];
            $fields['publish_at'] = $plan['status'] === 'scheduled' ? $plan['publish_at'] : null;
        }

        return $fields;
    }

    private function variantFields(array $given, array $plan): array
    {
        $fields = [];
        foreach (['sku', 'price_usd', 'cover_image_url'] as $column) {
            if (isset($given[$column])) {
                $fields[$column] = $given[$column];
            }
        }
        if (isset($given['isbn'])) {
            $fields['isbn'] = $given['isbn'];
        }
        if (isset($given['low_stock_threshold'])) {
            $fields['low_stock_threshold'] = (int) $given['low_stock_threshold'];
        }
        if (isset($given['is_active'])) {
            $fields['is_active'] = in_array(strtolower($given['is_active']), self::TRUE_VALUES, true);
        }

        return $fields;
    }

    private function syncRelations(Book $book, array $given, array $plan): void
    {
        if (isset($given['authors'])) {
            $ids = [];
            foreach (array_filter(array_map('trim', explode(';', $given['authors']))) as $name) {
                $ids[] = (Author::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
                    ?? Author::create(['name' => $name]))->id;
            }
            $book->authors()->sync(array_values(array_unique($ids)));
        }
        if ($plan['category_ids'] !== null) {
            $book->categories()->sync($plan['category_ids']);
        }
    }

    /** Undoes the export's formula guard (a leading ' before = + - @ tab or CR). */
    private static function unescape(string $value): string
    {
        return strlen($value) > 1 && $value[0] === "'" && in_array($value[1], ['=', '+', '-', '@', "\t", "\r"], true)
            ? substr($value, 1)
            : $value;
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Enums\BookFormat;
use App\Enums\InventoryReason;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookPublishingBulkCsvTest extends TestCase
{
    use RefreshDatabase;

    private StaffUser $admin;

    private StaffUser $staff;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = StaffUser::factory()->admin()->withTwoFactor()->create();
        $this->staff = StaffUser::factory()->withTwoFactor()->create();

        $this->customer = Customer::factory()->create([
            'email_verified_at' => now(),
        ]);
    }

    private function actingAsStaff(StaffUser $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken(
            $user->createToken('staff-test', $user->tokenAbilities())->plainTextToken
        );
    }

    private function actingAsCustomer(Customer $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken(
            $user->createToken('customer-test', ['customer'])->plainTextToken
        );
    }

    public function test_public_cannot_see_or_order_draft_or_scheduled_future_books_can_see_live_and_scheduled_past(): void
    {
        $category = Category::create(['name' => 'Fiction', 'slug' => 'fiction']);

        $liveBook = Book::create(['title' => 'Live Book', 'status' => 'live']);
        $liveBook->categories()->attach($category->id);
        $liveVariant = BookVariant::create([
            'book_id' => $liveBook->id,
            'format' => BookFormat::Paperback,
            'sku' => 'LIVE-PB',
            'price_usd' => '10.00',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $draftBook = Book::create(['title' => 'Draft Book', 'status' => 'draft']);
        $draftBook->categories()->attach($category->id);
        $draftVariant = BookVariant::create([
            'book_id' => $draftBook->id,
            'format' => BookFormat::Paperback,
            'sku' => 'DRAFT-PB',
            'price_usd' => '12.00',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $scheduledFuture = Book::create([
            'title' => 'Future Book',
            'status' => 'scheduled',
            'publish_at' => now()->addDays(2),
        ]);
        $scheduledFuture->categories()->attach($category->id);
        BookVariant::create([
            'book_id' => $scheduledFuture->id,
            'format' => BookFormat::Paperback,
            'sku' => 'FUTURE-PB',
            'price_usd' => '14.00',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        $scheduledPast = Book::create([
            'title' => 'Past Book',
            'status' => 'scheduled',
            'publish_at' => now()->subMinute(),
        ]);
        $scheduledPast->categories()->attach($category->id);
        BookVariant::create([
            'book_id' => $scheduledPast->id,
            'format' => BookFormat::Paperback,
            'sku' => 'PAST-PB',
            'price_usd' => '16.00',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        // 1. GET /api/v1/books: should return only live and scheduledPast
        $res = $this->getJson('/api/v1/books');
        $res->assertOk();
        $titles = collect($res->json('data'))->pluck('title')->all();
        $this->assertContains('Live Book', $titles);
        $this->assertContains('Past Book', $titles);
        $this->assertNotContains('Draft Book', $titles);
        $this->assertNotContains('Future Book', $titles);

        // 2. GET /api/v1/categories: count of books should be 2 (live + past)
        $catRes = $this->getJson('/api/v1/categories');
        $catRes->assertOk();
        $catItem = collect($catRes->json('data'))->firstWhere('id', $category->id);
        $this->assertSame(2, $catItem['books_count']);

        // 3. GET /api/v1/books/{id}: detail endpoint 404 for draft & future
        $this->getJson("/api/v1/books/{$draftBook->id}")->assertNotFound();
        $this->getJson("/api/v1/books/{$scheduledFuture->id}")->assertNotFound();
        $this->getJson("/api/v1/books/{$liveBook->id}")->assertOk();
        $this->getJson("/api/v1/books/{$scheduledPast->id}")->assertOk();

        // 4. GET /api/v1/books/{id}/reviews: 404 for draft
        $this->getJson("/api/v1/books/{$draftBook->id}/reviews")->assertNotFound();

        // 5. Wishlist: cannot add draft book
        $this->actingAsCustomer($this->customer)
            ->postJson('/api/v1/wishlist', ['book_id' => $draftBook->id])
            ->assertUnprocessable();

        // 6. Cart add: cannot add draft variant
        $this->actingAsCustomer($this->customer)
            ->postJson('/api/v1/cart/items', [
                'book_variant_id' => $draftVariant->id,
                'quantity' => 1,
            ])
            ->assertUnprocessable();

        // 7. Cart view & checkout: if an item was added before it became draft, it shows unavailable
        DB::table('cart_items')->insert([
            'cart_id' => $this->customer->cart()->firstOrCreate([])->id,
            'book_variant_id' => $draftVariant->id,
            'quantity' => 1,
            'unit_price_at_add' => '12.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cartRes = $this->actingAsCustomer($this->customer)->getJson('/api/v1/cart');
        $cartRes->assertOk();
        $this->assertFalse($cartRes->json('data.can_checkout'));
        $item = $cartRes->json('data.items.0');
        $this->assertSame('unavailable', $item['issues'][0]['code']);
    }

    public function test_staff_cannot_publish_or_schedule_books_admin_can(): void
    {
        // 1. Staff creates book without status -> default draft
        $resStaff = $this->actingAsStaff($this->staff)->postJson('/api/v1/staff/books', [
            'title' => 'Staff Draft Book',
        ]);
        $resStaff->assertCreated();
        $this->assertSame('draft', $resStaff->json('data.status'));
        $this->assertNull($resStaff->json('data.publish_at'));

        // 2. Staff attempts to create with status live -> 403
        $this->actingAsStaff($this->staff)->postJson('/api/v1/staff/books', [
            'title' => 'Staff Trying Live',
            'status' => 'live',
        ])->assertForbidden();

        // 3. Staff attempts to create with status scheduled -> 403
        $this->actingAsStaff($this->staff)->postJson('/api/v1/staff/books', [
            'title' => 'Staff Trying Scheduled',
            'status' => 'scheduled',
            'publish_at' => now()->addDay()->toIso8601String(),
        ])->assertForbidden();

        // 4. Staff attempts to update existing draft to live -> 403
        $bookId = $resStaff->json('data.id');
        $this->actingAsStaff($this->staff)->patchJson("/api/v1/staff/books/{$bookId}", [
            'status' => 'live',
        ])->assertForbidden();

        // 5. Admin can create draft, live, and scheduled
        $resAdminLive = $this->actingAsStaff($this->admin)->postJson('/api/v1/staff/books', [
            'title' => 'Admin Live Book',
            'status' => 'live',
        ]);
        $resAdminLive->assertCreated();
        $this->assertSame('live', $resAdminLive->json('data.status'));

        $future = now()->addDays(3)->startOfHour();
        $resAdminSched = $this->actingAsStaff($this->admin)->postJson('/api/v1/staff/books', [
            'title' => 'Admin Scheduled Book',
            'status' => 'scheduled',
            'publish_at' => $future->toIso8601String(),
        ]);
        $resAdminSched->assertCreated();
        $this->assertSame('scheduled', $resAdminSched->json('data.status'));

        // 6. Admin scheduling with past date -> 422
        $this->actingAsStaff($this->admin)->postJson('/api/v1/staff/books', [
            'title' => 'Bad Sched Book',
            'status' => 'scheduled',
            'publish_at' => now()->subDay()->toIso8601String(),
        ])->assertUnprocessable();

        // 7. Staff editing already live book keeps it live
        $liveId = $resAdminLive->json('data.id');
        $editRes = $this->actingAsStaff($this->staff)->patchJson("/api/v1/staff/books/{$liveId}", [
            'description' => 'Updated by staff safely',
        ]);
        $editRes->assertOk();
        $this->assertSame('live', $editRes->json('data.status'));
        $this->assertSame('Updated by staff safely', $editRes->json('data.description'));
    }

    public function test_bulk_books_and_bulk_variants_all_or_nothing_and_permissions(): void
    {
        $cat1 = Category::create(['name' => 'Sci-Fi', 'slug' => 'sci-fi']);
        $cat2 = Category::create(['name' => 'Fantasy', 'slug' => 'fantasy']);

        $b1 = Book::create(['title' => 'Book One', 'status' => 'draft']);
        $b2 = Book::create(['title' => 'Book Two', 'status' => 'draft']);

        $v1 = BookVariant::create([
            'book_id' => $b1->id,
            'format' => BookFormat::Paperback,
            'sku' => 'BULK-V1',
            'price_usd' => '20.00',
            'stock_quantity' => 5,
            'is_active' => true,
        ]);
        $v2 = BookVariant::create([
            'book_id' => $b2->id,
            'format' => BookFormat::Paperback,
            'sku' => 'BULK-V2',
            'price_usd' => '25.00',
            'stock_quantity' => 10,
            'is_active' => true,
        ]);

        // 1. Staff cannot bulk publish books -> 403
        $this->actingAsStaff($this->staff)->postJson('/api/v1/staff/books/bulk', [
            'ids' => [$b1->id, $b2->id],
            'status' => 'live',
        ])->assertForbidden();

        // 2. Staff cannot bulk change price -> 403
        $this->actingAsStaff($this->staff)->postJson('/api/v1/staff/variants/bulk', [
            'ids' => [$v1->id, $v2->id],
            'price_usd' => '15.00',
        ])->assertForbidden();

        // 3. Staff CAN bulk add categories and set stock / thresholds
        $staffBulkBooks = $this->actingAsStaff($this->staff)->postJson('/api/v1/staff/books/bulk', [
            'ids' => [$b1->id, $b2->id],
            'add_category_ids' => [$cat1->id],
        ]);
        $staffBulkBooks->assertOk();
        $this->assertSame(2, $staffBulkBooks->json('data.updated_count'));
        $this->assertTrue($b1->fresh()->categories->contains('id', $cat1->id));

        $staffBulkVariants = $this->actingAsStaff($this->staff)->postJson('/api/v1/staff/variants/bulk', [
            'ids' => [$v1->id, $v2->id],
            'stock_quantity' => 30,
            'low_stock_threshold' => 8,
        ]);
        $staffBulkVariants->assertOk();
        $this->assertSame(30, $v1->fresh()->stock_quantity);
        $this->assertSame(8, $v2->fresh()->low_stock_threshold);

        // Audit log verified
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'book_variant.bulk_updated',
            'entity_type' => 'book_variant',
            'entity_id' => $v1->id,
        ]);

        // 4. Admin bulk publishes
        $adminBulk = $this->actingAsStaff($this->admin)->postJson('/api/v1/staff/books/bulk', [
            'ids' => [$b1->id, $b2->id],
            'status' => 'live',
            'add_category_ids' => [$cat2->id],
        ]);
        $adminBulk->assertOk();
        $this->assertSame('live', $b1->fresh()->status);
        $this->assertSame('live', $b2->fresh()->status);
        $this->assertTrue($b2->fresh()->categories->contains('id', $cat2->id));
    }

    public function test_csv_export_formula_injection_and_bom(): void
    {
        // 1. Staff cannot export -> 403
        $this->actingAsStaff($this->staff)->get('/api/v1/staff/books/export')->assertForbidden();

        // 2. Admin exports CSV with BOM and formula injection protection
        $dangerousBook = Book::create([
            'title' => '=SUM(A1:A10)',
            'description' => '+cmd|/c calc',
            'status' => 'live',
        ]);
        BookVariant::create([
            'book_id' => $dangerousBook->id,
            'format' => BookFormat::Paperback,
            'sku' => '-SKU-DANGER',
            'price_usd' => '10.00',
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $res = $this->actingAsStaff($this->admin)->get('/api/v1/staff/books/export');
        $res->assertOk();
        $res->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $res->streamedContent();
        // Check UTF-8 BOM
        $this->assertTrue(str_starts_with($content, "\xEF\xBB\xBF"));

        // Check formula protection: values starting with =, +, - are prefixed with '
        $this->assertStringContainsString("'=SUM(A1:A10)", $content);
        $this->assertStringContainsString("'+cmd|/c calc", $content);
        $this->assertStringContainsString("'-SKU-DANGER", $content);

        // 3. Template download
        $templateRes = $this->actingAsStaff($this->admin)->get('/api/v1/staff/books/export-template');
        $templateRes->assertOk();
        $templateContent = $templateRes->getContent();
        $this->assertStringContainsString('The Great Gatsby', $templateContent);
    }

    private const CSV_HEADER = 'book_id,title,authors,categories,series,series_order,publisher,language,page_count,publish_date,description,status,publish_at,format,sku,isbn,price_usd,stock_quantity,low_stock_threshold,is_active,cover_image_url';

    private function preview(string $csv, ?StaffUser $as = null)
    {
        return $this->actingAsStaff($as ?? $this->admin)->postJson('/api/v1/staff/books/import/preview', [
            'file' => UploadedFile::fake()->createWithContent('books.csv', $csv),
        ]);
    }

    private function importCsv(string $csv)
    {
        $token = $this->preview($csv)->assertOk()->json('data.preview_token');

        return $this->actingAsStaff($this->admin)->postJson('/api/v1/staff/books/import/confirm', ['preview_token' => $token]);
    }

    public function test_csv_import_preview_classifications_and_validation(): void
    {
        Category::create(['name' => 'History', 'slug' => 'history']);
        $existingBook = Book::create(['title' => 'Ancient Rome', 'status' => 'live']);
        BookVariant::create(['book_id' => $existingBook->id, 'format' => BookFormat::Hardcover, 'sku' => 'ROME-HC',
            'isbn' => '978-0-12-345678-9', 'price_usd' => '30.00', 'stock_quantity' => 5, 'is_active' => true]);

        $csv = implode("\n", [
            self::CSV_HEADER,
            ',Ancient Rome (Updated),Author A,History,,,,English,300,2020-01-01,Updated text,live,,hardcover,rome-hc,,35.00,12,5,1,',
            ',,,,,,,,,,,,,hardcover,,9780123456789,36.00,,,,',
            ',Brand New Book,Author B,History,,,,English,200,2021-01-01,New book text,live,,paperback,NEW-001,9780987654321,19.99,25,5,1,',
            ',Unknown Cat Book,Author C,NonExistentCategory,,,,English,150,2022-01-01,,draft,,paperback,ERR-001,9781111111111,10.00,10,5,1,',
            ',Bad Price Book,Author D,History,,,,English,150,2022-01-01,,draft,,paperback,ERR-002,9782222222222,9.999,10,5,1,',
            ',Dup Book,Author E,,,,,,,,,,,paperback,NEW-001,,5.00,,,,',
            ',Late Book,Author F,,,,,,,,,scheduled,2020-01-01 09:00,paperback,ERR-003,,5.00,,,,',
            ',Minus Stock,Author G,,,,,,,,,,,paperback,ERR-004,,5.00,-5,,,',
        ]);

        $this->preview($csv, $this->staff)->assertForbidden();
        $res = $this->preview($csv)->assertOk();

        $this->assertSame(['total' => 8, 'new' => 1, 'update' => 2, 'error' => 5], $res->json('data.totals'));
        $rows = $res->json('data.rows');
        $this->assertSame(['update', 'update', 'new', 'error', 'error', 'error', 'error', 'error'], array_column($rows, 'action'));
        $this->assertSame([2, 3, 4, 5, 6, 7, 8, 9], array_column($rows, 'row_number'));
        $this->assertStringContainsString("doesn't exist", $rows[3]['messages'][0]);
        $this->assertStringContainsString('price usd', strtolower($rows[4]['messages'][0]));
        $this->assertStringContainsString('row 4 of this file', implode(' ', $rows[5]['messages']));
        $this->assertStringContainsString('in the future', implode(' ', $rows[6]['messages']));
        $this->assertStringContainsString('stock quantity', strtolower(implode(' ', $rows[7]['messages'])));
        $this->assertArrayNotHasKey('plan', $rows[0]);

        $this->assertDatabaseMissing('books', ['title' => 'Brand New Book']);
        $this->assertSame('Ancient Rome', $existingBook->fresh()->title);
    }

    public function test_csv_import_confirm_applies_valid_rows_in_one_transaction(): void
    {
        Category::create(['name' => 'Science', 'slug' => 'science']);
        $existingBook = Book::create(['title' => 'Physics 101', 'status' => 'draft']);
        $existingVariant = BookVariant::create(['book_id' => $existingBook->id, 'format' => BookFormat::Paperback,
            'sku' => 'PHYS-101', 'price_usd' => '15.00', 'stock_quantity' => 2, 'is_active' => true]);

        $res = $this->importCsv(implode("\n", [
            self::CSV_HEADER,
            ',Physics 101,Isaac Newton,Science,,,,,250,,Updated physics,live,,paperback,PHYS-101,,22.50,15,5,1,',
            ',Chemistry 101,Marie Curie,Science,,,,,280,,Intro to chemistry,live,,hardcover,CHEM-101,9781234567890,29.99,8,5,1,',
            ',Chemistry 101,Marie Curie,Science,,,,,280,,Intro to chemistry,live,,ebook,CHEM-101-E,,9.99,,,1,',
            ',Broken,,,,,,,,,,,,paperback,BROKEN-1,,abc,,,,',
        ]))->assertOk();
        $this->assertSame(['created' => 2, 'updated' => 1, 'skipped' => 1], array_intersect_key($res->json('data'), array_flip(['created', 'updated', 'skipped'])));
        $this->assertSame(5, $res->json('data.errors.0.row_number'));

        $existingBook->refresh();
        $existingVariant->refresh();
        $this->assertSame('live', $existingBook->status);
        $this->assertSame('22.50', $existingVariant->price_usd);
        $this->assertSame(15, $existingVariant->stock_quantity);
        $this->assertTrue($existingBook->authors->contains('name', 'Isaac Newton'));
        $this->assertDatabaseHas('inventory_movements', ['book_variant_id' => $existingVariant->id, 'change_qty' => 13, 'reason' => InventoryReason::Adjustment->value]);

        $chemistry = Book::where('title', 'Chemistry 101')->sole(); // two formats, one book
        $this->assertSame('live', $chemistry->status);
        $this->assertEqualsCanonicalizing(['CHEM-101', 'CHEM-101-E'], $chemistry->variants->pluck('sku')->all());
        $this->assertSame(8, $chemistry->variants->firstWhere('sku', 'CHEM-101')->stock_quantity);
    }

    public function test_blank_cells_keep_the_current_values_on_update(): void
    {
        $book = Book::create(['title' => 'Keep Me', 'status' => 'live', 'description' => 'Original', 'language' => 'Khmer']);
        $variant = BookVariant::create(['book_id' => $book->id, 'format' => BookFormat::Paperback, 'sku' => 'KEEP-1',
            'isbn' => '9780306406157', 'price_usd' => '10.00', 'stock_quantity' => 7, 'low_stock_threshold' => 2,
            'is_active' => false, 'cover_image_url' => 'https://example.com/c.jpg']);

        $this->importCsv("sku,price_usd\nKEEP-1,12.00\n")->assertOk();

        $book->refresh();
        $variant->refresh();
        $this->assertSame('12.00', $variant->price_usd);
        $this->assertSame(['live', 'Original', 'Khmer', 'Keep Me'], [$book->status, $book->description, $book->language, $book->title]);
        $this->assertSame([7, 2, false, '9780306406157', 'https://example.com/c.jpg'],
            [$variant->stock_quantity, $variant->low_stock_threshold, $variant->is_active, $variant->isbn, $variant->cover_image_url]);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_quoted_multi_line_cells_and_the_export_round_trip(): void
    {
        $book = Book::create(['title' => 'Round Trip', 'status' => 'live', 'description' => "Line one\nLine two, with comma"]);
        BookVariant::create(['book_id' => $book->id, 'format' => BookFormat::Paperback, 'sku' => '=RT-1', 'price_usd' => '10.00', 'stock_quantity' => 3, 'is_active' => true]);

        $csv = $this->actingAsStaff($this->admin)->get('/api/v1/staff/books/export')->streamedContent();
        $res = $this->preview($csv)->assertOk();

        $this->assertSame(['total' => 1, 'new' => 0, 'update' => 1, 'error' => 0], $res->json('data.totals'));
        $this->importCsv($csv)->assertOk();
        $this->assertSame("Line one\nLine two, with comma", $book->fresh()->description);
    }

    public function test_preview_token_is_single_use_and_tied_to_the_admin(): void
    {
        $token = $this->preview("title,format,sku,price_usd\nT,paperback,TOK-1,1.00\n")->assertOk()->json('data.preview_token');
        $otherAdmin = StaffUser::factory()->admin()->withTwoFactor()->create();

        $this->actingAsStaff($otherAdmin)->postJson('/api/v1/staff/books/import/confirm', ['preview_token' => $token])->assertUnprocessable();
        $this->actingAsStaff($this->admin)->postJson('/api/v1/staff/books/import/confirm', ['preview_token' => $token])->assertOk();
        $this->actingAsStaff($this->admin)->postJson('/api/v1/staff/books/import/confirm', ['preview_token' => $token])->assertUnprocessable();
        $this->actingAsStaff($this->admin)->postJson('/api/v1/staff/books/import/confirm', ['preview_token' => str_repeat('x', 40)])->assertUnprocessable();
    }

    public function test_confirm_checks_again_when_the_catalog_changed_after_the_preview(): void
    {
        $token = $this->preview("title,format,sku,price_usd\nRace,paperback,RACE-1,1.00\n")->assertOk()->json('data.preview_token');
        $other = Book::create(['title' => 'Other', 'status' => 'live']);
        BookVariant::create(['book_id' => $other->id, 'format' => BookFormat::Hardcover, 'sku' => 'RACE-1', 'price_usd' => '9.00', 'is_active' => true]);

        $res = $this->actingAsStaff($this->admin)->postJson('/api/v1/staff/books/import/confirm', ['preview_token' => $token]);

        // The row now matches the hardcover RACE-1, and a paperback can't become a hardcover: nothing is written.
        $res->assertUnprocessable();
        $this->assertSame('9.00', BookVariant::where('sku', 'RACE-1')->sole()->price_usd);
    }

    public function test_file_limits(): void
    {
        $rows = implode("\n", array_map(fn ($i) => "T{$i},paperback,LIM-{$i},1.00", range(1, 2001)));
        $this->preview("title,format,sku,price_usd\n{$rows}\n")->assertUnprocessable()->assertJsonValidationErrors('file');

        $big = UploadedFile::fake()->create('big.csv', 2049, 'text/csv');
        $this->actingAsStaff($this->admin)->postJson('/api/v1/staff/books/import/preview', ['file' => $big])->assertUnprocessable();

        $this->preview("title,format\nNo SKU,paperback\n")->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_bulk_variants_return_the_new_stock_and_refuse_deleted_books(): void
    {
        $book = Book::create(['title' => 'Bulk', 'status' => 'live']);
        $variant = BookVariant::create(['book_id' => $book->id, 'format' => BookFormat::Paperback, 'sku' => 'BULK-1', 'price_usd' => '5.00', 'stock_quantity' => 1, 'is_active' => true]);

        $this->actingAsStaff($this->staff)->postJson('/api/v1/staff/variants/bulk', ['ids' => [$variant->id], 'stock_quantity' => 9])
            ->assertOk()->assertJsonPath('data.items.0.stock_quantity', 9);

        $book->delete();
        $this->actingAsStaff($this->staff)->postJson('/api/v1/staff/variants/bulk', ['ids' => [$variant->id], 'stock_quantity' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->actingAsStaff($this->admin)->postJson('/api/v1/staff/books/bulk', ['ids' => [$book->id], 'status' => 'draft'])
            ->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->assertSame(9, $variant->fresh()->stock_quantity);
    }

    public function test_a_book_whose_scheduled_time_passed_can_still_be_edited(): void
    {
        $book = Book::create(['title' => 'Went live', 'status' => 'scheduled', 'publish_at' => now()->subHour()]);
        $sameTime = $book->publish_at->toIso8601String();

        $this->actingAsStaff($this->admin)->patchJson("/api/v1/staff/books/{$book->id}", ['title' => 'Went live (2nd ed.)', 'status' => 'scheduled', 'publish_at' => $sameTime])
            ->assertOk();
        $this->actingAsStaff($this->staff)->patchJson("/api/v1/staff/books/{$book->id}", ['title' => 'Typo fixed', 'status' => 'scheduled', 'publish_at' => $sameTime])
            ->assertOk();
        $this->actingAsStaff($this->staff)->patchJson("/api/v1/staff/books/{$book->id}", ['publish_at' => now()->addDay()->toIso8601String()])
            ->assertForbidden();
        $this->actingAsStaff($this->admin)->patchJson("/api/v1/staff/books/{$book->id}", ['status' => 'scheduled', 'publish_at' => now()->subMinute()->toIso8601String()])
            ->assertUnprocessable();
        $this->assertSame('Typo fixed', $book->fresh()->title);
    }
}

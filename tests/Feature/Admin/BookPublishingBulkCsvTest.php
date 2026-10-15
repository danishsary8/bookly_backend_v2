<?php

namespace Tests\Feature\Admin;

use App\Enums\BookFormat;
use App\Enums\InventoryReason;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Customer;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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

    public function test_csv_import_preview_classifications_and_validation(): void
    {
        $category = Category::create(['name' => 'History', 'slug' => 'history']);

        $existingBook = Book::create(['title' => 'Ancient Rome', 'status' => 'live']);
        $existingVariant = BookVariant::create([
            'book_id' => $existingBook->id,
            'format' => BookFormat::Hardcover,
            'sku' => 'ROME-HC',
            'isbn' => '9780123456789',
            'price_usd' => '30.00',
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        // CSV rows:
        // 1. Valid update by SKU
        // 2. Valid new book
        // 3. Invalid row: unknown category
        // 4. Invalid row: bad price
        $csvContent = implode("\n", [
            'book_id,title,authors,categories,series,series_order,publisher,language,page_count,publish_date,description,status,publish_at,format,sku,isbn,price_usd,stock_quantity,low_stock_threshold,is_active,cover_image_url',
            ',Ancient Rome (Updated),Author A,History,,,,English,300,2020-01-01,Updated text,live,,hardcover,ROME-HC,9780123456789,35.00,12,5,1,',
            ',Brand New Book,Author B,History,,,,English,200,2021-01-01,New book text,live,,paperback,NEW-001,9780987654321,19.99,25,5,1,',
            ',Unknown Cat Book,Author C,NonExistentCategory,,,,English,150,2022-01-01,,draft,,paperback,ERR-001,9781111111111,10.00,10,5,1,',
            ',Bad Price Book,Author D,History,,,,English,150,2022-01-01,,draft,,paperback,ERR-002,9782222222222,not_a_price,10,5,1,',
        ]);

        $file = UploadedFile::fake()->createWithContent('books.csv', $csvContent);

        // Staff cannot preview -> 403
        $this->actingAsStaff($this->staff)->postJson('/api/v1/staff/books/import/preview', [
            'file' => $file,
        ])->assertForbidden();

        // Admin previews
        $previewRes = $this->actingAsStaff($this->admin)->postJson('/api/v1/staff/books/import/preview', [
            'file' => $file,
        ]);
        $previewRes->assertOk();

        $token = $previewRes->json('preview_token');
        $this->assertNotEmpty($token);

        $totals = $previewRes->json('totals');
        $this->assertSame(4, $totals['total']);
        $this->assertSame(1, $totals['update']);
        $this->assertSame(1, $totals['new']);
        $this->assertSame(2, $totals['error']);

        $rows = $previewRes->json('rows');
        $this->assertSame('update', $rows[0]['action']);
        $this->assertSame('new', $rows[1]['action']);
        $this->assertSame('error', $rows[2]['action']);
        $this->assertStringContainsString("Category 'NonExistentCategory' does not exist", $rows[2]['messages'][0]);
        $this->assertSame('error', $rows[3]['action']);
        $this->assertStringContainsString('must be a valid amount', $rows[3]['messages'][0]);

        // Verify nothing was written to DB yet
        $this->assertDatabaseMissing('books', ['title' => 'Brand New Book']);
        $this->assertSame('Ancient Rome', $existingBook->fresh()->title);
    }

    public function test_csv_import_confirm_applies_valid_rows_in_one_transaction(): void
    {
        $category = Category::create(['name' => 'Science', 'slug' => 'science']);

        $existingBook = Book::create(['title' => 'Physics 101', 'status' => 'draft']);
        $existingVariant = BookVariant::create([
            'book_id' => $existingBook->id,
            'format' => BookFormat::Paperback,
            'sku' => 'PHYS-101',
            'price_usd' => '15.00',
            'stock_quantity' => 2,
            'is_active' => true,
        ]);

        $csvContent = implode("\n", [
            'book_id,title,authors,categories,series,series_order,publisher,language,page_count,publish_date,description,status,publish_at,format,sku,isbn,price_usd,stock_quantity,low_stock_threshold,is_active,cover_image_url',
            ',Physics 101,Isaac Newton,Science,,,,,250,,Updated physics,live,,paperback,PHYS-101,,22.50,15,5,1,',
            ',Chemistry 101,Marie Curie,Science,,,,,280,,Intro to chemistry,live,,hardcover,CHEM-101,9781234567890,29.99,8,5,1,',
        ]);

        $file = UploadedFile::fake()->createWithContent('import.csv', $csvContent);

        $previewRes = $this->actingAsStaff($this->admin)->postJson('/api/v1/staff/books/import/preview', [
            'file' => $file,
        ]);
        $previewRes->assertOk();
        $token = $previewRes->json('preview_token');

        // Confirm
        $confirmRes = $this->actingAsStaff($this->admin)->postJson('/api/v1/staff/books/import/confirm', [
            'preview_token' => $token,
        ]);
        $confirmRes->assertOk();
        $this->assertSame(2, $confirmRes->json('data.imported_count'));
        $this->assertSame(1, $confirmRes->json('data.totals.new'));
        $this->assertSame(1, $confirmRes->json('data.totals.update'));

        // Check updated book and variant
        $existingBook->refresh();
        $existingVariant->refresh();
        $this->assertSame('live', $existingBook->status);
        $this->assertSame('22.50', $existingVariant->price_usd);
        $this->assertSame(15, $existingVariant->stock_quantity);
        $this->assertTrue($existingBook->authors->contains('name', 'Isaac Newton'));

        // Check inventory movement was logged as adjustment
        $this->assertDatabaseHas('inventory_movements', [
            'book_variant_id' => $existingVariant->id,
            'change_qty' => 13, // 15 - 2
            'reason' => InventoryReason::Adjustment->value,
        ]);

        // Check new book and variant
        $newBook = Book::where('title', 'Chemistry 101')->first();
        $this->assertNotNull($newBook);
        $this->assertSame('live', $newBook->status);
        $this->assertTrue($newBook->authors->contains('name', 'Marie Curie'));
        $newVariant = $newBook->variants->first();
        $this->assertSame('CHEM-101', $newVariant->sku);
        $this->assertSame(8, $newVariant->stock_quantity);

        // Token should now be removed from cache (cannot confirm twice)
        $this->actingAsStaff($this->admin)->postJson('/api/v1/staff/books/import/confirm', [
            'preview_token' => $token,
        ])->assertUnprocessable();
    }
}

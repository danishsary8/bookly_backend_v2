<?php

namespace Tests\Feature\Ebooks;

use App\Enums\BookFormat;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\EbookDevice;
use App\Models\EbookFile;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Ebooks\EbookPages;
use App\Support\MaintenanceTasks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** Roadmap v3 phase 2 (owner 2026-11-06): ebooks read in the browser as watermarked page images. */
class EbookReaderTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private Book $book;

    private BookVariant $ebook;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['filesystems.ebook_disk' => 'local']);
        $this->book = Book::factory()->create(['title' => 'Angkor Stories']);
        BookVariant::factory()->for($this->book)->create(['format' => BookFormat::Paperback]);
        $this->ebook = BookVariant::factory()->for($this->book)->create(['format' => BookFormat::Ebook, 'stock_quantity' => 0]);
    }

    /** A small valid PDF with $pages pages, each showing its number. */
    private static function pdf(int $pages): string
    {
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', ''];
        $kids = [];
        for ($i = 1; $i <= $pages; $i++) {
            $pageObj = 3 + ($i - 1) * 2;
            $kids[] = "{$pageObj} 0 R";
            $stream = "BT /F1 48 Tf 72 700 Td (Page {$i}) Tj ET";
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents '.($pageObj + 1).' 0 R /Resources << /Font << /F1 '.(3 + $pages * 2).' 0 R >> >> >>';
            $objects[] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }
        $objects[1] = '<< /Type /Pages /Kids ['.implode(' ', $kids)."] /Count {$pages} >>";
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $n => $body) {
            $offsets[] = strlen($out);
            $out .= ($n + 1)." 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }

        return $out.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }

    private function upload(int $pages = 20): EbookFile
    {
        $staff = $this->asToken($this->staffToken());
        $staff->post("/api/v1/staff/books/{$this->book->id}/ebook", ['file' => UploadedFile::fake()->createWithContent('angkor.pdf', self::pdf($pages))], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.page_count', $pages)->assertJsonPath('data.status', 'processing');
        $this->app['auth']->forgetGuards();

        return EbookFile::where('book_id', $this->book->id)->first();
    }

    private function renderAll(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->artisan('ebooks:render')->assertSuccessful();
        }
    }

    private function owner(string $status = 'paid'): Customer
    {
        static $n = 0;
        $n++;
        $customer = Customer::factory()->create(['email_verified_at' => now(), 'email' => "reader{$n}@example.com"]);
        $order = Order::factory()->for($customer)->create(['status' => $status, 'placed_at' => now(), 'order_number' => "ORD-EBOOK-{$n}"]);
        OrderItem::factory()->for($order)->create(['book_variant_id' => $this->ebook->id, 'quantity' => 1]);

        return $customer;
    }

    private function asCustomer(Customer $customer): static
    {
        return $this->asToken($customer->createToken('t', ['customer'])->plainTextToken);
    }

    private function device(string $id = 'device-aaaaaaaaaaaaaaaa'): array
    {
        return ['X-Reader-Device' => $id];
    }

    public function test_staff_upload_a_pdf_and_it_is_rendered_into_pages_in_batches(): void
    {
        $file = $this->upload(20);
        Storage::disk('local')->assertExists($file->masterPath());

        $this->artisan('ebooks:render', ['--seconds' => 0])->expectsOutputToContain('rendered 15 page(s), 15/20')->assertSuccessful();
        $this->assertSame(EbookFile::STATUS_PROCESSING, $file->fresh()->status);
        $this->artisan('ebooks:render')->assertSuccessful();
        $this->assertSame(EbookFile::STATUS_READY, $file->fresh()->status);
        foreach ([1, 9, 20] as $page) {
            Storage::disk('local')->assertExists($file->pagePath($page));
        }
        $this->assertContains(['name' => 'ebook-pages', 'command' => 'ebooks:render', 'parameters' => [], 'cron' => '* * * * *'], MaintenanceTasks::all());
    }

    public function test_only_real_pdfs_are_accepted(): void
    {
        $staff = $this->asToken($this->staffToken());
        $url = "/api/v1/staff/books/{$this->book->id}/ebook";
        $staff->post($url, ['file' => UploadedFile::fake()->createWithContent('a.pdf', 'not a pdf')], ['Accept' => 'application/json'])->assertUnprocessable();
        $staff->post($url, ['file' => UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\ngarbage")], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $staff->post($url, ['file' => UploadedFile::fake()->createWithContent('a.txt', self::pdf(1))], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame(0, EbookFile::count());
    }

    public function test_an_owner_reads_watermarked_pages_and_progress_syncs(): void
    {
        $this->upload(4);
        $this->renderAll();
        $customer = $this->owner();
        $reader = $this->asCustomer($customer);

        $reader->getJson("/api/v1/reader/{$this->book->id}")->assertOk()
            ->assertJsonPath('data.owned', true)->assertJsonPath('data.page_count', 4)->assertJsonPath('data.status', 'ready');
        $page = $reader->get("/api/v1/reader/{$this->book->id}/pages/2", $this->device());
        $page->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->assertNotFalse(imagecreatefromstring($page->getContent()));
        // The watermark changes the pixels: the served page differs from the stored one.
        $this->assertNotSame(EbookPages::disk()->get(EbookFile::first()->pagePath(2)), $page->getContent());
        $reader->get("/api/v1/reader/{$this->book->id}/pages/5", $this->device())->assertNotFound();

        $reader->putJson("/api/v1/reader/{$this->book->id}/progress", ['page' => 3])->assertOk();
        $reader->putJson("/api/v1/reader/{$this->book->id}/progress", ['page' => 99])->assertOk()->assertJsonPath('data.page', 4);
        $library = $reader->getJson('/api/v1/me/ebooks')->assertOk()->json('data');
        $this->assertSame([['Angkor Stories', 4, 'ready']], array_map(fn ($r) => [$r['book']['title'], $r['last_page'], $r['status']], $library));
    }

    public function test_non_owners_unpaid_and_cancelled_orders_cannot_read(): void
    {
        $this->upload(4);
        $this->renderAll();
        foreach (['pending', 'cancelled', 'returned'] as $status) {
            $this->app['auth']->forgetGuards();
            $customer = $this->owner($status);
            $this->asCustomer($customer)->get("/api/v1/reader/{$this->book->id}/pages/1", $this->device())->assertForbidden();
            $this->asCustomer($customer)->getJson("/api/v1/reader/{$this->book->id}")->assertJsonPath('data.owned', false);
            $customer->delete();
        }
        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/reader/{$this->book->id}/pages/1", ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_pages_are_not_served_before_rendering_finishes(): void
    {
        $this->upload(20);
        $this->artisan('ebooks:render', ['--seconds' => 0]);
        $this->asCustomer($this->owner())->get("/api/v1/reader/{$this->book->id}/pages/1", $this->device())->assertStatus(409);
    }

    public function test_the_free_preview_needs_no_account_and_stops_at_the_limit(): void
    {
        $this->upload(20);
        $this->renderAll();

        $this->getJson("/api/v1/reader/{$this->book->id}/preview")->assertOk()->assertJsonPath('data.preview_pages', 2); // 10% of 20
        $this->get("/api/v1/reader/{$this->book->id}/preview/pages/2")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get("/api/v1/reader/{$this->book->id}/preview/pages/3")->assertNotFound();

        $staff = $this->asToken($this->staffToken());
        $staff->patchJson("/api/v1/staff/books/{$this->book->id}/ebook", ['preview_pages' => 5])->assertOk()->assertJsonPath('data.preview_limit', 5);
        $staff->patchJson("/api/v1/staff/books/{$this->book->id}/ebook", ['preview_pages' => 99])->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/reader/{$this->book->id}/preview/pages/5")->assertOk();
    }

    public function test_three_devices_at_most_one_removal_a_month_and_staff_can_reset(): void
    {
        $this->upload(4);
        $this->renderAll();
        $customer = $this->owner();
        $reader = $this->asCustomer($customer);
        $url = "/api/v1/reader/{$this->book->id}/pages/1";

        foreach (['phone-aaaaaaaaaaaaaaaa', 'laptop-aaaaaaaaaaaaaa', 'tablet-aaaaaaaaaaaaaa'] as $id) {
            $reader->get($url, $this->device($id))->assertOk();
        }
        $reader->get($url, $this->device('phone-aaaaaaaaaaaaaaaa'))->assertOk(); // same device again is fine
        $reader->get($url, $this->device('fourth-aaaaaaaaaaaaaa'))->assertStatus(409);
        $reader->get($url, ['X-Reader-Device' => 'x'])->assertStatus(400);

        $devices = $reader->getJson('/api/v1/me/ebook-devices', $this->device('laptop-aaaaaaaaaaaaaa'))->assertOk()
            ->assertJsonPath('meta.max_devices', 3)->assertJsonCount(3, 'data')->json('data');
        $this->assertSame(1, collect($devices)->where('is_current', true)->count());
        $this->assertArrayNotHasKey('device_hash', $devices[0]);

        $phone = EbookDevice::where('device_hash', hash('sha256', 'phone-aaaaaaaaaaaaaaaa'))->first();
        $tablet = EbookDevice::where('device_hash', hash('sha256', 'tablet-aaaaaaaaaaaaaa'))->first();
        $reader->deleteJson("/api/v1/me/ebook-devices/{$phone->id}")->assertNoContent();
        $reader->get($url, $this->device('fourth-aaaaaaaaaaaaaa'))->assertOk();
        $reader->deleteJson("/api/v1/me/ebook-devices/{$tablet->id}")->assertStatus(429); // one a month

        $this->travel(31)->days();
        $reader->deleteJson("/api/v1/me/ebook-devices/{$tablet->id}")->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->asToken($this->staffToken(admin: true))->postJson("/api/v1/staff/customers/{$customer->id}/ebook-devices/reset")->assertOk();
        $this->assertSame(0, EbookDevice::where('customer_id', $customer->id)->count());
    }

    public function test_re_uploading_replaces_the_pages_and_removing_deletes_the_files(): void
    {
        $first = $this->upload(4);
        $this->renderAll();
        $second = $this->upload(6);
        Storage::disk('local')->assertMissing($first->masterPath());
        $this->assertSame(6, $second->page_count);
        $this->assertSame(0, $second->pages_rendered);

        $staff = $this->asToken($this->staffToken());
        $staff->getJson("/api/v1/staff/books/{$this->book->id}/ebook")->assertOk()->assertJsonPath('data.page_count', 6);
        $staff->deleteJson("/api/v1/staff/books/{$this->book->id}/ebook")->assertNoContent();
        Storage::disk('local')->assertMissing($second->masterPath());
        $staff->getJson("/api/v1/staff/books/{$this->book->id}/ebook")->assertOk()->assertJsonPath('data', null);
    }
}

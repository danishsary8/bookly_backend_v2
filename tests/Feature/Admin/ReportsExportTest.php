<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use OpenSpout\Reader\XLSX\Reader;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class ReportsExportTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    private const URL = '/api/v1/staff/reports/export?from=2026-10-01&to=2026-10-10';

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.timezone' => 'Asia/Phnom_Penh']);
        $this->travelTo(Carbon::parse('2026-10-10 10:00:00', 'UTC'));
    }

    private string $lastFile = '';

    /** @return array<string, list<list<mixed>>> sheet name => rows of cell values */
    private function download(): array
    {
        $response = $this->asToken($this->staffToken(admin: true))->get(self::URL);
        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertDownload('bookly-report-2026-10-01-to-2026-10-10.xlsx');

        $base = $response->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $base);
        $path = $base->getFile()->getPathname();
        $this->lastFile = (string) file_get_contents($path);

        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = array_map(fn ($cell) => $cell->getValue(), $row->cells);
            }
        }
        $reader->close();
        unlink($path);

        return $sheets;
    }

    /** The raw XML of one sheet of the last download (sheet4 = Top customers). */
    private function sheetXml(int $number): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        file_put_contents($tmp, $this->lastFile);
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $xml = (string) $zip->getFromName("xl/worksheets/sheet{$number}.xml");
        $zip->close();
        unlink($tmp);

        return $xml;
    }

    public function test_an_admin_downloads_every_report_as_one_excel_file(): void
    {
        $buyer = Customer::factory()->create(['name' => 'Sokha Chan', 'email' => 'sokha@example.test']);
        $this->deliveredOrder($buyer, [[$this->physical('12.50'), 2]], deliveredAt: now()->subDay());

        $sheets = $this->download();

        $this->assertSame(['Summary', 'Sales by day', 'Top books', 'Top customers', 'Orders', 'Coupons and refunds', 'Offers'], array_keys($sheets));

        $summary = collect($sheets['Summary'])->keyBy(fn ($row) => $row[0] ?? '');
        $this->assertSame(25.0, (float) $summary['Gross revenue (delivered orders)'][1]);  // a real number, not text
        $this->assertSame(1, (int) $summary['Orders delivered'][1]);
        $this->assertSame(2, (int) $summary['Books sold'][1]);

        $this->assertCount(2 + 10, $sheets['Sales by day']);                     // title, header, 10 days
        $this->assertSame(['Sokha Chan', 'sokha@example.test'], array_slice($sheets['Top customers'][2], 0, 2));
    }

    public function test_text_that_looks_like_a_formula_stays_text(): void
    {
        $buyer = Customer::factory()->create(['name' => '=HYPERLINK("http://evil.test","Click")', 'email' => 'x@example.test']);
        $this->deliveredOrder($buyer, [[$this->physical(), 1]], deliveredAt: now()->subDay());

        $this->assertSame('=HYPERLINK("http://evil.test","Click")', $this->download()['Top customers'][2][0]);

        // Stored as a plain text cell: no <f> (formula) element anywhere in the sheet, so Excel never runs it.
        $xml = $this->sheetXml(4);
        $this->assertStringContainsString('<is><t>=HYPERLINK(&quot;http://evil.test&quot;,&quot;Click&quot;)</t></is>', $xml);
        $this->assertStringNotContainsString('<f>', $xml);
    }

    public function test_only_admins_with_valid_dates_get_the_file(): void
    {
        $this->asToken($this->staffToken(admin: false))->getJson(self::URL)->assertForbidden();

        $admin = $this->staffToken(admin: true);
        $before = glob(sys_get_temp_dir().'/bookly-report-*') ?: [];
        $this->asToken($admin)->getJson('/api/v1/staff/reports/export')->assertUnprocessable();
        $this->asToken($admin)->getJson('/api/v1/staff/reports/export?from=2026-10-10&to=2026-10-01')->assertUnprocessable();
        $this->asToken($admin)->getJson('/api/v1/staff/reports/export?from=2024-01-01&to=2026-10-10')
            ->assertUnprocessable()->assertJsonValidationErrors(['to']);

        // A refused range leaves no temp file behind.
        $this->assertSame($before, glob(sys_get_temp_dir().'/bookly-report-*') ?: []);
    }
}

<?php

namespace Tests\Feature\Catalog;

use App\Models\Customer;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    private StaffUser $admin;

    private StaffUser $staff;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->admin = StaffUser::factory()->admin()->withTwoFactor()->create(['is_active' => true]);
        $this->staff = StaffUser::factory()->withTwoFactor()->create([
            'role' => 'staff',
            'is_active' => true,
            'permissions' => ['catalog:manage'],
        ]);
        $this->customer = Customer::factory()->create(['email_verified_at' => now()]);
    }

    private function actingAsStaff(StaffUser $user): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken(
            $user->createToken('staff-test', $user->tokenAbilities())->plainTextToken
        );
    }

    /**
     * Constructs a valid standalone PNG with arbitrary dimensions without needing PHP's GD extension.
     */
    private function makePng(string $name, int $width, int $height, int $sizeBytes = 1024): UploadedFile
    {
        $temp = tempnam(sys_get_temp_dir(), 'img_test_');
        $ihdrData = pack('N', $width).pack('N', $height)."\x08\x02\x00\x00\x00";
        $png = "\x89PNG\r\n\x1a\n"
            ."\x00\x00\x00\x0dIHDR".$ihdrData.pack('N', crc32('IHDR'.$ihdrData))
            ."\x00\x00\x00\x00IEND\xae\x42\x60\x82";
        if (strlen($png) < $sizeBytes) {
            $png .= str_repeat("\0", $sizeBytes - strlen($png));
        }
        file_put_contents($temp, $png);

        return new UploadedFile($temp, $name, 'image/png', null, true);
    }

    public function test_guest_cannot_upload_media(): void
    {
        $file = $this->makePng('cover.png', 600, 800);
        $this->postJson('/api/v1/staff/media/upload', ['image' => $file])
            ->assertUnauthorized();
    }

    public function test_customer_cannot_upload_media(): void
    {
        $file = $this->makePng('cover.png', 600, 800);
        $token = $this->customer->createToken('customer-token', ['customer'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/staff/media/upload', ['image' => $file])
            ->assertForbidden();
    }

    public function test_staff_with_catalog_manage_can_upload_valid_image(): void
    {
        $file = $this->makePng('great-gatsby.png', 800, 1200, 2048);

        $response = $this->actingAsStaff($this->staff)
            ->postJson('/api/v1/staff/media/upload', ['image' => $file]);

        $response->assertCreated()
            ->assertJsonStructure([
                'url',
                'path',
                'filename',
                'width',
                'height',
                'size_bytes',
                'mime',
            ]);

        $path = $response->json('path');
        Storage::disk('public')->assertExists($path);
        $this->assertSame(800, $response->json('width'));
        $this->assertSame(1200, $response->json('height'));
        $this->assertSame('image/png', $response->json('mime'));
    }

    public function test_rejects_file_exceeding_5mb(): void
    {
        // 5.5 MB file (5500 * 1024 bytes)
        $bigFile = $this->makePng('huge.png', 1200, 1600, 5500 * 1024);

        $this->actingAsStaff($this->admin)
            ->postJson('/api/v1/staff/media/upload', ['image' => $bigFile])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }

    public function test_rejects_non_image_file(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'doc_test_');
        file_put_contents($temp, 'This is a PDF text payload, not an image.');
        $fakeDoc = new UploadedFile($temp, 'malicious.pdf', 'application/pdf', null, true);

        $this->actingAsStaff($this->admin)
            ->postJson('/api/v1/staff/media/upload', ['image' => $fakeDoc])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }

    public function test_rejects_image_smaller_than_minimum_dimensions(): void
    {
        // 50x50 is below min 100x100
        $tinyFile = $this->makePng('tiny.png', 50, 50);

        $this->actingAsStaff($this->admin)
            ->postJson('/api/v1/staff/media/upload', ['image' => $tinyFile])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }

    public function test_rejects_image_larger_than_maximum_dimensions(): void
    {
        // 4500x4500 is above max 4000x4000
        $hugeDimensions = $this->makePng('billboard.png', 4500, 4500);

        $this->actingAsStaff($this->admin)
            ->postJson('/api/v1/staff/media/upload', ['image' => $hugeDimensions])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['image']);
    }

    public function test_uploads_go_to_the_configured_media_disk_and_return_its_public_url(): void
    {
        // Production stores covers in Cloudflare R2: Render's own disk is wiped at every deploy.
        config(['filesystems.media_disk' => 'r2', 'filesystems.disks.r2.url' => 'https://media.bookly.test']);
        Storage::fake('r2');

        $res = $this->actingAsStaff($this->admin)
            ->postJson('/api/v1/staff/media/upload', ['image' => $this->makePng('cover.png', 600, 900)])
            ->assertCreated();

        Storage::disk('r2')->assertExists($res->json('path'));
        Storage::disk('public')->assertMissing($res->json('path'));
        $this->assertStringStartsWith('covers/', $res->json('path'));
    }

    public function test_the_r2_disk_is_an_s3_disk_with_a_public_url(): void
    {
        $disk = config('filesystems.disks.r2');

        $this->assertSame('s3', $disk['driver']);
        $this->assertSame('auto', $disk['region']);
        $this->assertArrayHasKey('endpoint', $disk);
        $this->assertArrayHasKey('url', $disk);
        $this->assertSame('public', config('filesystems.media_disk'), 'local default stays the public disk');
    }
}

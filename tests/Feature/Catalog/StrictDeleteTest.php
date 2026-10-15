<?php

namespace Tests\Feature\Catalog;

use App\Models\AdminAuditLog;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\StaffUser;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class StrictDeleteTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private function adminWithSecret(string $password = 'Password123!', string $secret = 'JBSWY3DPEHPK3PXP'): array
    {
        $admin = StaffUser::factory()->admin()->withTwoFactor($secret)->create([
            'password_hash' => $password,
        ]);
        $token = $admin->createToken('test', $admin->tokenAbilities())->plainTextToken;

        return [$admin, $token];
    }

    private function validTotp(string $secret = 'JBSWY3DPEHPK3PXP'): string
    {
        return app(TotpService::class)->codeAt($secret, now()->timestamp);
    }

    public function test_guest_and_non_admin_cannot_delete_book(): void
    {
        $book = BookVariant::factory()->create()->book;
        $staffToken = $this->staffToken(admin: false);

        $this->deleteJson("/api/v1/staff/books/{$book->id}")->assertUnauthorized();
        $this->asToken($staffToken)->deleteJson("/api/v1/staff/books/{$book->id}")->assertForbidden();
    }

    public function test_admin_delete_book_requires_confirm_matching_title(): void
    {
        $book = Book::factory()->create(['title' => 'Secret Garden']);
        BookVariant::factory()->for($book)->create();
        [$admin, $token] = $this->adminWithSecret();

        // Missing payload
        $this->asToken($token)->deleteJson("/api/v1/staff/books/{$book->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirm', 'password', 'code']);

        // Mismatched confirm
        $res = $this->asToken($token)->deleteJson("/api/v1/staff/books/{$book->id}", [
            'confirm' => 'Wrong Title',
            'password' => 'Password123!',
            'code' => $this->validTotp(),
        ]);

        $res->assertUnprocessable();
        $this->assertStringContainsString('Secret Garden', $res->json('errors.confirm.0'));
        $this->assertNotSoftDeleted($book);
    }

    public function test_admin_delete_book_requires_correct_password(): void
    {
        $book = Book::factory()->create(['title' => '1984']);
        BookVariant::factory()->for($book)->create();
        [$admin, $token] = $this->adminWithSecret();

        $res = $this->asToken($token)->deleteJson("/api/v1/staff/books/{$book->id}", [
            'confirm' => '1984',
            'password' => 'WrongPassword',
            'code' => $this->validTotp(),
        ]);

        $res->assertUnprocessable()
            ->assertJsonPath('errors.password.0', 'That password is incorrect.');
        $this->assertNotSoftDeleted($book);
    }

    public function test_admin_delete_book_requires_valid_2fa_code_and_locks_after_5_failures(): void
    {
        $book = Book::factory()->create(['title' => 'Brave New World']);
        BookVariant::factory()->for($book)->create();
        [$admin, $token] = $this->adminWithSecret();

        for ($i = 0; $i < 5; $i++) {
            $this->asToken($token)->deleteJson("/api/v1/staff/books/{$book->id}", [
                'confirm' => 'Brave New World',
                'password' => 'Password123!',
                'code' => '000000',
            ])->assertUnprocessable()->assertJsonPath('errors.code.0', 'Invalid authentication code.');
        }

        // 6th attempt is throttled
        $this->asToken($token)->deleteJson("/api/v1/staff/books/{$book->id}", [
            'confirm' => 'Brave New World',
            'password' => 'Password123!',
            'code' => $this->validTotp(),
        ])->assertStatus(429)->assertHeader('Retry-After');

        $this->assertNotSoftDeleted($book);
    }

    public function test_admin_delete_book_happy_path_soft_deletes_audits_and_alerts(): void
    {
        config(['services.ops_alerts.token' => 'test-token', 'services.ops_alerts.chat_id' => '12345']);
        Http::fake();

        $book = Book::factory()->create(['title' => 'Dune']);
        BookVariant::factory()->for($book)->create();
        [$admin, $token] = $this->adminWithSecret();

        $res = $this->asToken($token)->deleteJson("/api/v1/staff/books/{$book->id}", [
            'confirm' => 'Dune',
            'password' => 'Password123!',
            'code' => $this->validTotp(),
        ]);

        $res->assertOk()->assertJsonPath('data.id', $book->id);
        $this->assertSoftDeleted('books', ['id' => $book->id]);

        // Audit log recorded
        $log = AdminAuditLog::where('entity_id', $book->id)->where('action', 'book.deleted')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->staff_user_id);
        $this->assertNull($log->before_data['deleted_at']);
        $this->assertNotNull($log->after_data['deleted_at']);

        // Telegram alert sent
        Http::assertSent(function ($request) use ($admin) {
            return str_contains($request->url(), 'sendMessage')
                && str_contains($request['text'], 'Dune')
                && str_contains($request['text'], $admin->name);
        });
    }

    public function test_admin_restore_book_within_30_days_succeeds(): void
    {
        $book = Book::factory()->create(['title' => 'Fahrenheit 451']);
        BookVariant::factory()->for($book)->create();
        $book->delete();
        $book->forceFill(['deleted_at' => now()->subDays(15)])->save();

        [$admin, $token] = $this->adminWithSecret();

        $this->asToken($token)->postJson("/api/v1/staff/books/{$book->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.id', $book->id);

        $this->assertNotSoftDeleted($book);
    }

    public function test_admin_restore_book_older_than_30_days_fails_with_422(): void
    {
        $book = Book::factory()->create(['title' => 'Animal Farm']);
        BookVariant::factory()->for($book)->create();
        $book->delete();
        $book->forceFill(['deleted_at' => now()->subDays(31)])->save();

        [$admin, $token] = $this->adminWithSecret();

        $res = $this->asToken($token)->postJson("/api/v1/staff/books/{$book->id}/restore");
        $res->assertUnprocessable();
        $this->assertStringContainsString('30 days', $res->json('message'));

        $this->assertSoftDeleted('books', ['id' => $book->id]);
    }
}

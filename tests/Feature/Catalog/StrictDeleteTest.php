<?php

namespace Tests\Feature\Catalog;

use App\Models\Author;
use App\Models\Book;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\Series;
use App\Models\StaffUser;
use App\Services\Auth\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class StrictDeleteTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    /**
     * @return array{0: StaffUser, 1: string}
     */
    private function createAdmin(string $secret = 'JBSWY3DPEHPK3PXP'): array
    {
        $admin = StaffUser::factory()->admin()->withTwoFactor($secret)->create([
            'password_hash' => 'AdminPassword123!',
        ]);

        $token = $admin->createToken('test', $admin->tokenAbilities())->plainTextToken;

        return [$admin, $token];
    }

    private function validCode(string $secret = 'JBSWY3DPEHPK3PXP'): string
    {
        return app(TotpService::class)->codeAt($secret, time());
    }

    public function test_delete_book_requires_strict_verification(): void
    {
        [$admin, $token] = $this->createAdmin();
        $book = Book::factory()->create(['title' => 'The Great Gatsby']);

        // Mismatched confirmation title
        $response = $this->asToken($token)->deleteJson("/api/v1/staff/books/{$book->id}", [
            'confirm' => 'Wrong Title',
            'password' => 'AdminPassword123!',
            'code' => $this->validCode(),
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('errors.confirm.0', 'Type "The Great Gatsby" to confirm.');
        $this->assertDatabaseHas('books', ['id' => $book->id, 'deleted_at' => null]);

        // Wrong password
        $response = $this->asToken($token)->deleteJson("/api/v1/staff/books/{$book->id}", [
            'confirm' => 'The Great Gatsby',
            'password' => 'WrongPassword!',
            'code' => $this->validCode(),
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('errors.password.0', 'That password is incorrect.');
        $this->assertDatabaseHas('books', ['id' => $book->id, 'deleted_at' => null]);

        // Wrong 2FA code
        $response = $this->asToken($token)->deleteJson("/api/v1/staff/books/{$book->id}", [
            'confirm' => 'The Great Gatsby',
            'password' => 'AdminPassword123!',
            'code' => '000000',
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('errors.code.0', 'Invalid authentication code.');
        $this->assertDatabaseHas('books', ['id' => $book->id, 'deleted_at' => null]);

        // Successful strict delete
        $response = $this->asToken($token)->deleteJson("/api/v1/staff/books/{$book->id}", [
            'confirm' => 'The Great Gatsby',
            'password' => 'AdminPassword123!',
            'code' => $this->validCode(),
        ]);
        $response->assertStatus(204);
        $this->assertSoftDeleted('books', ['id' => $book->id]);
    }

    public function test_strict_delete_locks_out_after_five_failed_2fa_attempts(): void
    {
        [$admin, $token] = $this->createAdmin();
        $book = Book::factory()->create(['title' => 'Security Book']);

        RateLimiter::clear("staff-2fa-wrong:{$admin->id}");

        for ($i = 0; $i < 5; $i++) {
            $this->asToken($token)->deleteJson("/api/v1/staff/books/{$book->id}", [
                'confirm' => 'Security Book',
                'password' => 'AdminPassword123!',
                'code' => '999999',
            ])->assertStatus(422);
        }

        // 6th attempt hits rate limiter
        $response = $this->asToken($token)->deleteJson("/api/v1/staff/books/{$book->id}", [
            'confirm' => 'Security Book',
            'password' => 'AdminPassword123!',
            'code' => $this->validCode(),
        ]);
        $response->assertStatus(429);
    }

    public function test_author_strict_delete_and_conflict_when_in_use(): void
    {
        [$admin, $token] = $this->createAdmin();
        $author = Author::factory()->create(['name' => 'F. Scott Fitzgerald']);
        $book = Book::factory()->create();
        $book->authors()->attach($author->id);

        // Conflict: author is linked to active book
        $response = $this->asToken($token)->deleteJson("/api/v1/staff/authors/{$author->id}", [
            'confirm' => 'F. Scott Fitzgerald',
            'password' => 'AdminPassword123!',
            'code' => $this->validCode(),
        ]);
        $response->assertStatus(409)
            ->assertJsonFragment(['message' => 'This author is linked to 1 book(s). Remove them from those books first.']);

        // Detach from book
        $book->authors()->detach($author->id);

        // Strict delete succeeds
        $response = $this->asToken($token)->deleteJson("/api/v1/staff/authors/{$author->id}", [
            'confirm' => 'F. Scott Fitzgerald',
            'password' => 'AdminPassword123!',
            'code' => $this->validCode(),
        ]);
        $response->assertStatus(204);
        $this->assertSoftDeleted('authors', ['id' => $author->id]);
    }

    public function test_trash_index_and_restore_workflow(): void
    {
        [$admin, $token] = $this->createAdmin();

        $book = Book::factory()->create(['title' => 'Trashed Novel']);
        $author = Author::factory()->create(['name' => 'Trashed Author']);
        $category = Category::factory()->create(['name' => 'Trashed Fiction']);
        $publisher = Publisher::factory()->create(['name' => 'Trashed Publishing']);
        $series = Series::factory()->create(['name' => 'Trashed Trilogy']);

        $book->delete();
        $author->delete();
        $category->delete();
        $publisher->delete();
        $series->delete();

        // Trash index lists all soft-deleted items
        $response = $this->asToken($token)->getJson('/api/v1/staff/trash');
        $response->assertStatus(200)
            ->assertJsonPath('total', 5);

        // Filter by type=books
        $response = $this->asToken($token)->getJson('/api/v1/staff/trash?type=books');
        $response->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Trashed Novel')
            ->assertJsonPath('data.0.type', 'book')
            ->assertJsonPath('data.0.days_until_purge', 30);

        // Restore book
        $restoreRes = $this->asToken($token)->postJson("/api/v1/staff/trash/book/{$book->id}/restore");
        $restoreRes->assertStatus(200)
            ->assertJsonPath('message', 'Restored Trashed Novel successfully.')
            ->assertJsonPath('item.id', $book->id);
        $this->assertDatabaseHas('books', ['id' => $book->id, 'deleted_at' => null]);

        // Restore author
        $restoreAuthorRes = $this->asToken($token)->postJson("/api/v1/staff/trash/author/{$author->id}/restore");
        $restoreAuthorRes->assertStatus(200)
            ->assertJsonPath('item.id', $author->id);
        $this->assertDatabaseHas('authors', ['id' => $author->id, 'deleted_at' => null]);
    }

    public function test_purge_trash_command_cleans_up_records_older_than_30_days(): void
    {
        $oldBook = Book::factory()->create(['title' => 'Ancient Book']);
        $oldBook->delete();
        // Set deleted_at to 35 days ago
        Book::withTrashed()->where('id', $oldBook->id)->update(['deleted_at' => now()->subDays(35)]);

        $recentBook = Book::factory()->create(['title' => 'Recent Book']);
        $recentBook->delete();
        // Set deleted_at to 5 days ago
        Book::withTrashed()->where('id', $recentBook->id)->update(['deleted_at' => now()->subDays(5)]);

        $this->artisan('trash:purge', ['--days' => 30])
            ->expectsOutputToContain('Purged 1 books older than 30 days.')
            ->assertSuccessful();

        $this->assertDatabaseMissing('books', ['id' => $oldBook->id]);
        $this->assertSoftDeleted('books', ['id' => $recentBook->id]);
    }
}

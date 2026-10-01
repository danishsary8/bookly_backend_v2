<?php

namespace Tests\Feature\Catalog;

use App\Models\AdminAuditLog;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Publisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class StaffBookManagementTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_staff_creates_a_book_with_authors_and_categories(): void
    {
        $token = $this->staffToken();
        $authors = Author::factory()->count(2)->create();
        $category = Category::factory()->create();

        $response = $this->asToken($token)->postJson('/api/v1/staff/books', [
            'title' => 'A Wizard of Earthsea',
            'publisher_id' => Publisher::factory()->create()->id,
            'author_ids' => $authors->pluck('id')->all(),
            'category_ids' => [$category->id],
            'publish_date' => '1968-11-01',
        ])->assertCreated()
            ->assertJsonPath('data.language', 'English')
            ->assertJsonPath('data.is_visible', false)
            ->assertJsonCount(2, 'data.authors');

        $id = $response->json('data.id');
        $log = AdminAuditLog::where('action', 'book.created')->firstOrFail();
        $this->assertSame($id, $log->entity_id);
        $this->assertCount(2, $log->after_data['author_ids']);

        // Not public until it has an active variant.
        $this->getJson("/api/v1/books/{$id}")->assertNotFound();
    }

    public function test_validation_rejects_unknown_relations(): void
    {
        $this->asToken($this->staffToken())->postJson('/api/v1/staff/books', [
            'title' => 'X', 'author_ids' => [999], 'publisher_id' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors(['author_ids.0', 'publisher_id']);
    }

    public function test_update_syncs_relations_and_logs_only_changes(): void
    {
        $token = $this->staffToken();
        $book = Book::factory()->create(['title' => 'Old', 'language' => 'English']);
        $book->authors()->attach($first = Author::factory()->create());
        $second = Author::factory()->create();

        $this->asToken($token)->patchJson("/api/v1/staff/books/{$book->id}", ['title' => 'New', 'author_ids' => [$second->id]])
            ->assertOk()->assertJsonPath('data.title', 'New')->assertJsonPath('data.authors.0.id', $second->id);

        $log = AdminAuditLog::where('action', 'book.updated')->firstOrFail();
        $this->assertSame(['title' => 'Old', 'author_ids' => [$first->id]], $log->before_data);
        $this->assertSame(['title' => 'New', 'author_ids' => [$second->id]], $log->after_data);

        $this->asToken($token)->patchJson("/api/v1/staff/books/{$book->id}", ['language' => null])->assertUnprocessable();
    }

    public function test_staff_list_includes_hidden_books_and_trashed_filter(): void
    {
        $token = $this->staffToken();
        Book::factory()->create(['title' => 'Draft']);
        BookVariant::factory()->create();
        Book::factory()->create(['title' => 'Gone'])->delete();

        $this->asToken($token)->getJson('/api/v1/staff/books')->assertOk()->assertJsonCount(2, 'data');
        $this->asToken($token)->getJson('/api/v1/staff/books?trashed=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Gone');
        $this->asToken($token)->getJson('/api/v1/staff/books?q=draf')->assertJsonCount(1, 'data');
    }

    public function test_only_admin_soft_deletes_and_restores(): void
    {
        $book = BookVariant::factory()->create()->book;
        $staff = $this->staffToken();
        $admin = $this->staffToken(admin: true);

        $this->asToken($staff)->deleteJson("/api/v1/staff/books/{$book->id}")->assertForbidden();
        $this->asToken($admin)->deleteJson("/api/v1/staff/books/{$book->id}")->assertNoContent();
        $this->assertSoftDeleted($book);
        $this->getJson("/api/v1/books/{$book->id}")->assertNotFound();

        $this->asToken($admin)->getJson("/api/v1/staff/books/{$book->id}")->assertOk();
        $this->asToken($admin)->postJson("/api/v1/staff/books/{$book->id}/restore")->assertOk();
        $this->getJson("/api/v1/books/{$book->id}")->assertOk();
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'book.restored', 'entity_id' => $book->id]);
    }
}

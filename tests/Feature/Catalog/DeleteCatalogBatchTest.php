<?php

namespace Tests\Feature\Catalog;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteCatalogBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_refuses_partial_editions_and_preserves_relations_then_trashes_complete_match(): void
    {
        $book = Book::factory()->create();
        $first = BookVariant::factory()->for($book)->create();
        $second = BookVariant::factory()->for($book)->create(['format' => 'hardcover']);
        $author = Author::factory()->create();
        $book->authors()->attach($author);
        $file = tempnam(sys_get_temp_dir(), 'bookly-batch-');
        try {
            file_put_contents($file, "\xEF\xBB\xBFsku\n{$first->sku}\n");
            $this->artisan('catalog:delete-batch', ['csv' => $file, '--force' => true])->assertFailed();
            $this->assertFalse($book->fresh()->trashed());
            $this->assertCount(1, $book->fresh()->authors);
            file_put_contents($file, "sku\n{$first->sku}\n{$second->sku}\n");
            $this->artisan('catalog:delete-batch', ['csv' => $file, '--dry-run' => true])->assertSuccessful();
            $this->assertFalse($book->fresh()->trashed());
            $this->artisan('catalog:delete-batch', ['csv' => $file, '--force' => true])->assertFailed();
            $this->assertFalse($book->fresh()->trashed());
            $admin = StaffUser::factory()->admin()->create();
            $this->artisan('catalog:delete-batch', ['csv' => $file, '--force' => true, '--staff' => $admin->id])->assertSuccessful();
            $this->assertSoftDeleted($book);
            $this->assertCount(2, Book::withTrashed()->find($book->id)->variants);
            $this->assertCount(1, Book::withTrashed()->find($book->id)->authors);
            $this->assertDatabaseHas('admin_audit_logs', ['staff_user_id' => $admin->id, 'action' => 'book.deleted', 'entity_id' => $book->id]);
        } finally {
            unlink($file);
        }
    }
}

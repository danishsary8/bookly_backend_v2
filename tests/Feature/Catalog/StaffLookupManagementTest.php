<?php

namespace Tests\Feature\Catalog;

use App\Models\Author;
use App\Models\Book;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Publisher;
use App\Models\Series;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class StaffLookupManagementTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_staff_creates_and_updates_authors_with_audit_log(): void
    {
        $token = $this->staffToken();

        $id = $this->asToken($token)->postJson('/api/v1/staff/authors', ['name' => 'Ursula Le Guin', 'bio' => 'Wizard of Earthsea'])
            ->assertCreated()->assertJsonPath('data.name', 'Ursula Le Guin')->json('data.id');
        $this->asToken($token)->patchJson("/api/v1/staff/authors/{$id}", ['name' => 'Ursula K. Le Guin'])
            ->assertOk()->assertJsonPath('data.name', 'Ursula K. Le Guin');

        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'author.created', 'entity_id' => $id]);
        $log = \App\Models\AdminAuditLog::where('action', 'author.updated')->firstOrFail();
        $this->assertSame(['name' => 'Ursula Le Guin'], $log->before_data);
        $this->assertSame(['name' => 'Ursula K. Le Guin'], $log->after_data);
    }

    public function test_category_slug_is_generated_and_kept_unique(): void
    {
        $token = $this->staffToken();

        $this->asToken($token)->postJson('/api/v1/staff/categories', ['name' => 'Science Fiction'])
            ->assertCreated()->assertJsonPath('data.slug', 'science-fiction');
        $this->asToken($token)->postJson('/api/v1/staff/categories', ['name' => 'Science Fiction'])
            ->assertCreated()->assertJsonPath('data.slug', 'science-fiction-2');
        $this->asToken($token)->postJson('/api/v1/staff/categories', ['name' => 'X', 'slug' => 'science-fiction'])
            ->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_publisher_names_are_unique_and_series_can_be_created(): void
    {
        $token = $this->staffToken();
        Publisher::factory()->create(['name' => 'Penguin']);

        $this->asToken($token)->postJson('/api/v1/staff/publishers', ['name' => 'Penguin'])->assertUnprocessable();
        $this->asToken($token)->postJson('/api/v1/staff/publishers', ['name' => 'Tor'])->assertCreated();
        $this->asToken($token)->postJson('/api/v1/staff/series', ['name' => 'Earthsea Cycle'])->assertCreated();
    }

    public function test_only_admin_can_delete_and_items_in_use_are_protected(): void
    {
        $staff = $this->staffToken();
        $admin = $this->staffToken(admin: true);
        $unused = Author::factory()->create();
        $used = Author::factory()->create();
        Book::factory()->create()->authors()->attach($used);

        $this->asToken($staff)->deleteJson("/api/v1/staff/authors/{$unused->id}")->assertForbidden();
        $this->asToken($admin)->deleteJson("/api/v1/staff/authors/{$used->id}")->assertStatus(409);
        $this->asToken($admin)->deleteJson("/api/v1/staff/authors/{$unused->id}")->assertNoContent();

        $this->assertModelMissing($unused);
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'author.deleted', 'entity_id' => $unused->id]);
    }

    public function test_in_use_check_counts_soft_deleted_books_too(): void
    {
        $admin = $this->staffToken(admin: true);
        $category = Category::factory()->create();
        $series = Series::factory()->create();
        $book = Book::factory()->create(['series_id' => $series->id]);
        $book->categories()->attach($category);
        $book->delete();

        $this->asToken($admin)->deleteJson("/api/v1/staff/categories/{$category->id}")->assertStatus(409);
        $this->asToken($admin)->deleteJson("/api/v1/staff/series/{$series->id}")->assertStatus(409);
    }

    public function test_customers_and_guests_cannot_manage_catalog(): void
    {
        $customer = Customer::factory()->create()->createToken('t', ['customer'])->plainTextToken;

        $this->postJson('/api/v1/staff/authors', ['name' => 'X'])->assertUnauthorized();
        $this->asToken($customer)->postJson('/api/v1/staff/authors', ['name' => 'X'])->assertForbidden();
    }
}

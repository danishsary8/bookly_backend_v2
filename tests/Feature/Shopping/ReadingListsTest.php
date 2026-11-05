<?php

namespace Tests\Feature\Shopping;

use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\ReadingList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class ReadingListsTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private function customer(string $name = 'Danish Reader'): Customer
    {
        $customer = Customer::factory()->create(['name' => $name]);
        $this->asToken($customer->createToken('test', ['customer'])->plainTextToken);

        return $customer;
    }

    public function test_private_lists_return_404_even_to_another_signed_in_customer_and_public_lists_only_expose_first_name(): void
    {
        $this->customer();
        $list = $this->postJson('/api/v1/reading-lists', ['title' => 'My books'])->assertCreated()->json('data');
        $this->customer('Other Reader');
        $this->getJson('/api/v1/lists/'.$list['slug'])->assertNotFound();
        $this->patchJson('/api/v1/reading-lists/'.$list['id'], ['title' => 'Stolen'])->assertNotFound();
        $this->deleteJson('/api/v1/reading-lists/'.$list['id'])->assertNotFound();
        ReadingList::find($list['id'])->update(['is_public' => true]);
        $result = $this->getJson('/api/v1/lists/'.$list['slug'])->assertOk()->assertJsonPath('data.owner_first_name', 'Danish')->json('data');
        $this->assertArrayNotHasKey('customer_id', $result);
        $this->assertArrayNotHasKey('email', $result);
    }

    public function test_books_notes_and_removal_follow_ownership_and_plain_text_rules(): void
    {
        $this->customer();
        $id = $this->postJson('/api/v1/reading-lists', ['title' => 'Reading'])->json('data.id');
        $book = BookVariant::factory()->create()->book;
        $this->postJson("/api/v1/reading-lists/{$id}/books", ['book_id' => $book->id, 'note' => 'Read next'])
            ->assertOk()->assertJsonPath('data.books.0.note', 'Read next');
        $this->postJson("/api/v1/reading-lists/{$id}/books", ['book_id' => $book->id])->assertOk()->assertJsonCount(1, 'data.books');
        $this->postJson("/api/v1/reading-lists/{$id}/books", ['book_id' => $book->id, 'note' => '<script>alert(1)</script>'])->assertUnprocessable();
        $this->deleteJson("/api/v1/reading-lists/{$id}/books/{$book->id}")->assertOk()->assertJsonPath('data.books', []);
        $this->postJson('/api/v1/reading-lists', ['title' => '<img src=x>'])->assertUnprocessable();
    }

    public function test_limits_are_enforced_and_existing_entries_can_be_updated_at_capacity(): void
    {
        $customer = $this->customer();
        for ($i = 0; $i < 20; $i++) {
            ReadingList::forceCreate(['customer_id' => $customer->id, 'title' => 'List '.$i, 'slug' => 'list-'.$i]);
        }
        $this->postJson('/api/v1/reading-lists', ['title' => 'Too many'])->assertUnprocessable();
        $list = ReadingList::first();
        $books = BookVariant::factory()->count(201)->create()->pluck('book_id');
        $list->books()->sync($books->take(200)->mapWithKeys(fn ($id, $position) => [$id => ['position' => $position]])->all());
        $this->postJson("/api/v1/reading-lists/{$list->id}/books", ['book_id' => $books->last()])->assertUnprocessable();
        $this->postJson("/api/v1/reading-lists/{$list->id}/books", ['book_id' => $books->first(), 'note' => 'Updated'])->assertOk();
    }

    public function test_only_staff_can_pick_a_public_list_and_privacy_revokes_it(): void
    {
        $customer = $this->customer();
        $token = $customer->createToken('owner', ['customer'])->plainTextToken;
        $id = $this->postJson('/api/v1/reading-lists', ['title' => 'Staff reads', 'is_public' => true, 'is_staff_pick' => true])->json('data.id');
        $this->assertFalse(ReadingList::find($id)->is_staff_pick);
        $this->patchJson("/api/v1/staff/reading-lists/{$id}", ['is_staff_pick' => true])->assertForbidden();
        $this->asToken($this->staffToken())->patchJson("/api/v1/staff/reading-lists/{$id}", ['is_staff_pick' => true])->assertOk();
        $this->getJson('/api/v1/lists/staff-picks')->assertOk()->assertJsonCount(1, 'data');
        $this->asToken($token)->patchJson("/api/v1/reading-lists/{$id}", ['is_public' => false])->assertOk();
        $this->getJson('/api/v1/lists/staff-picks')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_add_all_skips_unavailable_and_existing_books_and_private_lists(): void
    {
        $this->customer();
        $list = $this->postJson('/api/v1/reading-lists', ['title' => 'To buy', 'is_public' => true])->json('data');
        $available = BookVariant::factory()->create(['stock_quantity' => 4]);
        $out = BookVariant::factory()->create(['stock_quantity' => 0]);
        ReadingList::find($list['id'])->books()->attach([$available->book_id => ['position' => 0], $out->book_id => ['position' => 1]]);
        $this->postJson('/api/v1/lists/'.$list['slug'].'/cart')->assertOk()->assertJsonPath('data.added', 1)->assertJsonPath('data.skipped', 1);
        $this->postJson('/api/v1/lists/'.$list['slug'].'/cart')->assertOk()->assertJsonPath('data.added', 0)->assertJsonPath('data.skipped', 2);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.items.0.quantity', 1);
        $this->patchJson('/api/v1/reading-lists/'.$list['id'], ['is_public' => false])->assertOk();
        $this->postJson('/api/v1/lists/'.$list['slug'].'/cart')->assertNotFound();
    }

    public function test_reordering_and_write_rate_limit(): void
    {
        $this->customer();
        $list = $this->postJson('/api/v1/reading-lists', ['title' => 'Order'])->json('data');
        $a = BookVariant::factory()->create();
        $b = BookVariant::factory()->create();
        $this->postJson('/api/v1/reading-lists/'.$list['id'].'/books', ['book_id' => $a->book_id])->assertOk();
        $this->postJson('/api/v1/reading-lists/'.$list['id'].'/books', ['book_id' => $b->book_id])->assertOk();
        $this->postJson('/api/v1/reading-lists/'.$list['id'].'/books', ['book_id' => $b->book_id, 'position' => 0])->assertOk()->assertJsonPath('data.books.0.book.id', $b->book_id);
        for ($i = 0; $i < 16; $i++) {
            $this->patchJson('/api/v1/reading-lists/'.$list['id'], ['title' => 'Order'])->assertOk();
        }
        $this->patchJson('/api/v1/reading-lists/'.$list['id'], ['title' => 'Order'])->assertTooManyRequests();
    }
}

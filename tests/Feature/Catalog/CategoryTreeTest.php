<?php

namespace Tests\Feature\Catalog;

use App\Enums\BookFormat;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\StarterCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** Roadmap v3 phase 1 (owner 2026-11-05): category tree, audience fields, more filters and sorts, filter counts. */
class CategoryTreeTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        StarterCategories::install(); // what the migration does outside tests
    }

    private function book(string $title, array $categories = [], array $attrs = [], array $variant = []): Book
    {
        $book = Book::factory()->create(['title' => $title, ...$attrs]);
        BookVariant::factory()->for($book)->create(['format' => BookFormat::Paperback, 'stock_quantity' => 5, ...$variant]);
        $book->categories()->sync(array_map(fn (Category $c) => $c->id, $categories));

        return $book;
    }

    private function titles(string $query): array
    {
        return collect($this->getJson('/api/v1/books?'.$query)->assertOk()->json('data'))->pluck('title')->sort()->values()->all();
    }

    public function test_the_starter_tree_is_installed_and_existing_categories_are_kept(): void
    {
        // Installed in setUp, as the migration does: 12 top-level categories with children.
        $this->assertSame(12, Category::topLevel()->count());
        $this->assertSame('fiction', Category::where('slug', 'fantasy')->first()->parent->slug);

        // An existing category with a starter slug keeps its name and is placed under its parent; others stay put.
        Category::where('slug', 'manga')->forceDelete();
        $mine = Category::create(['name' => 'Manga & anime (mine)', 'slug' => 'manga']);
        $other = Category::create(['name' => 'Bookmarks', 'slug' => 'bookmarks']);
        $before = Category::count();

        $this->assertSame(0, StarterCategories::install()); // safe to run again
        $this->assertSame($before, Category::count());
        $this->assertSame('Manga & anime (mine)', $mine->fresh()->name);
        $this->assertSame('comics-manga', $mine->fresh()->parent->slug);
        $this->assertNull($other->fresh()->parent_id);
    }

    public function test_the_menu_tree_nests_children_counts_each_book_once_and_hides_empty_categories(): void
    {
        $fiction = Category::where('slug', 'fiction')->first();
        $fantasy = Category::where('slug', 'fantasy')->first();
        $romance = Category::where('slug', 'romance')->first();
        $this->book('Dragons', [$fantasy, $romance]);
        $this->book('Love', [$romance]);
        $this->book('Plain fiction', [$fiction]);

        $tree = collect($this->getJson('/api/v1/categories?tree=1')->assertOk()->json('data'));
        $this->assertSame(['fiction'], $tree->pluck('slug')->all()); // empty top-level categories hidden
        $node = $tree->first();
        $this->assertSame(3, $node['total_books']); // Dragons counted once
        $this->assertSame(['fantasy', 'romance'], collect($node['children'])->pluck('slug')->sort()->values()->all());
        $this->assertSame(2, collect($node['children'])->firstWhere('slug', 'romance')['total_books']);

        // The flat list still has every category (for admin pickers), with parent ids.
        $flat = collect($this->getJson('/api/v1/categories')->json('data'));
        $this->assertSame($fiction->id, $flat->firstWhere('slug', 'fantasy')['parent_id']);
        $this->assertGreaterThan(50, $flat->count());
    }

    public function test_a_parent_category_shows_its_children_and_several_categories_mean_any(): void
    {
        $fiction = Category::where('slug', 'fiction')->first();
        $fantasy = Category::where('slug', 'fantasy')->first();
        $manga = Category::where('slug', 'manga')->first();
        $this->book('Dragons', [$fantasy]);
        $this->book('One Piece', [$manga]);
        $this->book('History of Angkor', [Category::where('slug', 'history')->first()]);

        $this->assertSame(['Dragons'], $this->titles("category_id={$fiction->id}"));
        $this->assertSame(['Dragons', 'One Piece'], $this->titles("category_ids[]={$fantasy->id}&category_ids[]={$manga->id}"));
    }

    public function test_age_grade_level_ebook_and_several_formats(): void
    {
        $this->book('Baby book', attrs: ['age_min' => 0, 'age_max' => 3]);
        $this->book('Teen book', attrs: ['age_min' => 12]);
        $this->book('Khmer grade 9', attrs: ['school_grade' => 9]);
        $this->book('English File B1', attrs: ['level' => 'B1']);
        $ebook = $this->book('Has ebook');
        BookVariant::factory()->for($ebook)->create(['format' => BookFormat::Ebook]);
        $this->book('Hardback', variant: ['format' => BookFormat::Hardcover]);

        $this->assertSame(['Baby book'], $this->titles('age=2'));
        $this->assertSame(['Teen book'], $this->titles('age=15'));
        $this->assertSame(['Khmer grade 9'], $this->titles('school_grade=9'));
        $this->assertSame(['English File B1'], $this->titles('level=B1'));
        $this->assertSame(['Has ebook'], $this->titles('has_ebook=1'));
        $this->assertSame(['Hardback', 'Has ebook'], $this->titles('formats[]=hardcover&formats[]=ebook'));
        $this->getJson('/api/v1/books?level=Z9')->assertUnprocessable();
        $this->getJson('/api/v1/books?age=40')->assertUnprocessable();
    }

    public function test_best_selling_and_most_reviewed_sorts(): void
    {
        $quiet = $this->book('Quiet');
        $hit = $this->book('Hit');
        $customer = Customer::factory()->create();
        $order = Order::factory()->for($customer)->create(['placed_at' => now()->subDays(3), 'status' => 'paid']);
        OrderItem::factory()->for($order)->create(['book_variant_id' => $hit->variants()->first()->id, 'quantity' => 4]);
        $old = Order::factory()->for($customer)->create(['placed_at' => now()->subDays(200), 'status' => 'delivered']);
        OrderItem::factory()->for($old)->create(['book_variant_id' => $quiet->variants()->first()->id, 'quantity' => 50]); // too old to count

        $this->assertSame(['Hit', 'Quiet'], collect($this->getJson('/api/v1/books?sort=best_selling')->json('data'))->pluck('title')->all());
        $this->getJson('/api/v1/books?sort=most_reviewed')->assertOk();
    }

    public function test_filter_counts_follow_the_other_filters(): void
    {
        $fantasy = Category::where('slug', 'fantasy')->first();
        $manga = Category::where('slug', 'manga')->first();
        $this->book('Dragons', [$fantasy], ['language' => 'English']);
        $this->book('Naruto', [$manga], ['language' => 'Khmer', 'level' => 'A2']);

        $facets = $this->getJson('/api/v1/books?facets=1')->assertOk()->json('facets');
        $this->assertSame(1, $facets['categories'][(string) Category::where('slug', 'fiction')->value('id')]);
        $this->assertSame(1, $facets['categories'][(string) Category::where('slug', 'comics-manga')->value('id')]);
        $this->assertSame(['English' => 1, 'Khmer' => 1], collect($facets['languages'])->sortKeys()->all());
        $this->assertSame(2, $facets['formats']['paperback']);
        $this->assertSame(['A2' => 1], $facets['levels']);
        $this->assertSame(0, $facets['with_age']);

        $this->assertSame(['Khmer' => 1], $this->getJson('/api/v1/books?facets=1&language=Khmer')->json('facets.languages'));
        $this->assertArrayNotHasKey('facets', $this->getJson('/api/v1/books')->json());
    }

    public function test_staff_build_the_tree_two_levels_deep_and_set_audience_fields(): void
    {
        $staff = $this->asToken($this->staffToken());
        $top = $staff->postJson('/api/v1/staff/categories', ['name' => 'Gifts', 'icon' => 'gift', 'sort_order' => 5])->assertCreated()->json('data');
        $child = $staff->postJson('/api/v1/staff/categories', ['name' => 'Bookmarks', 'parent_id' => $top['id']])->assertCreated()->assertJsonPath('data.parent_id', $top['id'])->json('data');

        $staff->postJson('/api/v1/staff/categories', ['name' => 'Too deep', 'parent_id' => $child['id']])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $staff->patchJson("/api/v1/staff/categories/{$top['id']}", ['parent_id' => $top['id']])->assertUnprocessable();
        $staff->patchJson("/api/v1/staff/categories/{$top['id']}", ['parent_id' => Category::where('slug', 'fiction')->value('id')])->assertUnprocessable(); // has children
        $staff->patchJson("/api/v1/staff/categories/{$child['id']}", ['parent_id' => null])->assertOk()->assertJsonPath('data.parent_id', null);
        $staff->postJson('/api/v1/staff/categories', ['name' => 'Bad icon', 'icon' => '<script>'])->assertUnprocessable();

        $book = $this->book('English File');
        $staff->patchJson("/api/v1/staff/books/{$book->id}", ['level' => 'B2', 'school_grade' => 10, 'age_min' => 12, 'age_max' => 18])->assertOk()
            ->assertJsonPath('data.level', 'B2')->assertJsonPath('data.school_grade', 10);
        $staff->patchJson("/api/v1/staff/books/{$book->id}", ['age_min' => 10, 'age_max' => 5])->assertUnprocessable()->assertJsonValidationErrors('age_max');
        $this->getJson("/api/v1/books/{$book->id}")->assertOk()->assertJsonPath('data.level', 'B2')->assertJsonPath('data.age_min', 12);
    }

    public function test_a_category_with_sub_categories_cannot_be_deleted(): void
    {
        $staff = $this->asToken($this->staffToken(admin: true));
        $fiction = Category::where('slug', 'fiction')->first();
        $staff->deleteJson("/api/v1/staff/categories/{$fiction->id}", ['confirm' => 'Fiction', 'password' => 'x', 'code' => '1'])->assertStatus(409);
    }
}

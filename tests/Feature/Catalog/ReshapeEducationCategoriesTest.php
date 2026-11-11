<?php

namespace Tests\Feature\Catalog;

use App\Models\Book;
use App\Models\Category;
use App\Support\StarterCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReshapeEducationCategoriesTest extends TestCase
{
    use RefreshDatabase;

    /** The old tree, as production has it. */
    private function oldTree(): void
    {
        $parents = ['khmer-school' => ['Khmer school books', ['grade-7' => 'Grade 7', 'grade-9' => 'Grade 9', 'exam-prep' => 'Exam preparation']],
            'english-learning' => ['English learning', ['english-coursebooks' => 'Coursebooks', 'grammar' => 'Grammar']],
            'education' => ['Education & reference', ['mathematics' => 'Mathematics', 'other-languages' => 'Other languages', 'reference' => 'Reference']]];
        foreach ($parents as $slug => [$name, $children]) {
            $parent = Category::create(['slug' => $slug, 'name' => $name]);
            foreach ($children as $childSlug => $childName) {
                Category::create(['slug' => $childSlug, 'name' => $childName, 'parent_id' => $parent->id]);
            }
        }
    }

    private function book(string $title, string $category): Book
    {
        $book = Book::factory()->create(['title' => $title]);
        $book->categories()->attach(Category::where('slug', $category)->value('id'));

        return $book;
    }

    private function slugsOf(Book $book): array
    {
        return $book->fresh()->categories()->pluck('slug')->sort()->values()->all();
    }

    public function test_moves_books_to_the_eight_subjects_and_removes_the_old_categories(): void
    {
        $this->oldTree();
        $maths = $this->book('គណិតវិទ្យា ថ្នាក់ទី៧ (Mathematics Grade 7)', 'grade-7');
        $history = $this->book('ប្រវត្តិវិទ្យា ថ្នាក់ទី៩ (History Grade 9)', 'grade-9');
        $khmer = $this->book('ភាសាខ្មែរ ថ្នាក់ទី៧ (Khmer Language Grade 7)', 'grade-7');
        $exam = $this->book('Bac II practice papers', 'exam-prep');
        $oxford = $this->book('English File Intermediate', 'english-coursebooks');
        $french = $this->book('French for beginners', 'other-languages');

        $this->artisan('catalog:education-categories')->assertSuccessful();

        $this->assertSame(['mathematics'], $this->slugsOf($maths));
        $this->assertSame(['world-history'], $this->slugsOf($history));
        $this->assertSame(['language-learning'], $this->slugsOf($khmer));
        $this->assertSame(['education'], $this->slugsOf($exam));
        $this->assertSame(['english-books'], $this->slugsOf($oxford));
        $this->assertSame(['language-learning'], $this->slugsOf($french));

        $education = Category::where('slug', 'education')->sole();
        $this->assertSame('Education', $education->name);
        $this->assertSame(array_keys(StarterCategories::TREE['education']['children']), Category::where('parent_id', $education->id)->orderBy('sort_order')->pluck('slug')->all());
        foreach (['khmer-school', 'grade-7', 'grade-9', 'exam-prep', 'english-learning', 'english-coursebooks', 'grammar', 'other-languages', 'reference'] as $gone) {
            $this->assertSoftDeleted('categories', ['slug' => $gone]);
        }

        // Again: nothing more to move.
        $this->artisan('catalog:education-categories')->expectsOutputToContain('Moved 0 book link(s)')->assertSuccessful();
        $this->assertSame(['mathematics'], $this->slugsOf($maths));
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->oldTree();
        $maths = $this->book('Mathematics Grade 7', 'grade-7');
        $before = DB::table('categories')->count();

        $this->artisan('catalog:education-categories', ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertSame(['grade-7'], $this->slugsOf($maths));
        $this->assertSame($before, DB::table('categories')->count());
        $this->assertNull(Category::where('slug', 'grade-7')->value('deleted_at'));
    }
}

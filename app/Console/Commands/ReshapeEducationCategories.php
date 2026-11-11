<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Support\StarterCategories;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off reshape of the education categories (owner 2026-11-11). Education becomes one group with eight subjects:
 * Mathematics, Physics, Chemistry, Biology, World history, Geography, English books (Oxford, Cambridge…) and
 * Language learning. The old Khmer school books (Grade 7-12, Exam preparation), English learning and Education &
 * reference categories are removed (soft-deleted); their books move first:
 *   - a grade book goes to its subject, read from its title ("Mathematics Grade 7" -> Mathematics); its grade stays on
 *     the book (school_grade), so the shop's grade filter keeps working;
 *   - English learning books go to English books; other languages to Language learning;
 *   - anything else (exam preparation, computers, study skills, reference…) stays under Education itself.
 * Safe to run again. --dry-run shows what would change and changes nothing.
 */
class ReshapeEducationCategories extends Command
{
    protected $signature = 'catalog:education-categories {--dry-run : Show what would change without changing anything}';

    protected $description = 'Reshapes Education into eight subject categories and moves the books of the old school and English categories.';

    /** Removed category slug => where its books go (null: by the book's title, for grade books). */
    private const MOVES = [
        'khmer-school' => 'education', 'grade-7' => null, 'grade-8' => null, 'grade-9' => null, 'grade-10' => null,
        'grade-11' => null, 'grade-12' => null, 'exam-prep' => 'education',
        'english-learning' => 'english-books', 'english-coursebooks' => 'english-books', 'grammar' => 'english-books',
        'vocabulary' => 'english-books', 'graded-readers' => 'english-books', 'ielts-toefl' => 'english-books',
        'dictionaries' => 'english-books',
        'other-languages' => 'language-learning', 'computers' => 'education', 'study-skills' => 'education', 'reference' => 'education',
    ];

    /** Subject words in a grade book's title => subject slug (first match wins). */
    private const SUBJECTS = [
        'math' => 'mathematics', 'physics' => 'physics', 'chemistry' => 'chemistry', 'biology' => 'biology',
        'history' => 'world-history', 'geography' => 'geography', 'english' => 'english-books', 'language' => 'language-learning',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        DB::beginTransaction();
        try {
            StarterCategories::install();
            $education = Category::where('slug', 'education')->firstOrFail();
            $education->update(['name' => 'Education', 'icon' => 'graduation-cap', 'parent_id' => null]);

            // The eight subjects, in order, under Education (an existing one elsewhere is moved here).
            $order = 0;
            $target = ['education' => $education->id];
            foreach (StarterCategories::TREE['education']['children'] as $slug => $name) {
                $category = Category::withTrashed()->where('slug', $slug)->firstOrFail();
                $category->forceFill(['name' => $name, 'parent_id' => $education->id, 'sort_order' => $order += 10, 'deleted_at' => null])->save();
                $target[$slug] = $category->id;
            }

            foreach (self::MOVES as $slug => $to) {
                $old = Category::where('slug', $slug)->first();
                if (! $old) {
                    continue;
                }
                $books = DB::table('book_categories')->join('books', 'books.id', '=', 'book_categories.book_id')
                    ->where('book_categories.category_id', $old->id)->pluck('books.title', 'books.id');
                foreach ($books as $bookId => $title) {
                    $dest = $to ?? $this->subjectOf((string) $title);
                    DB::table('book_categories')->insertOrIgnore(['book_id' => $bookId, 'category_id' => $target[$dest]]);
                    $rows[] = [$old->name, $title, Category::find($target[$dest])->name];
                }
                DB::table('book_categories')->where('category_id', $old->id)->delete();
                // Categories staff added under a removed group keep living, under Education.
                Category::where('parent_id', $old->id)->whereNotIn('slug', array_keys(self::MOVES))->update(['parent_id' => $education->id]);
                $old->delete();
            }

            $dryRun ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->table(['From', 'Book', 'To'], $rows);
        $this->info(($dryRun ? 'Dry run, nothing changed. Would move ' : 'Moved ').count($rows).' book link(s); Education now has its eight subjects.');

        return self::SUCCESS;
    }

    private function subjectOf(string $title): string
    {
        $lower = mb_strtolower($title);
        foreach (self::SUBJECTS as $word => $slug) {
            if (str_contains($lower, $word)) {
                return $slug;
            }
        }

        return 'education';
    }
}

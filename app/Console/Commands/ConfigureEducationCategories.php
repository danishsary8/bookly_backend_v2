<?php

namespace App\Console\Commands;

use App\Enums\BookFormat;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\Series;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ConfigureEducationCategories extends Command
{
    protected $signature = 'catalog:education-setup
                            {--dry-run : Preview changes without making database modifications}
                            {--prune-non-education : Soft-delete or hide non-educational categories like romance}
                            {--import-batch : Import the 24-book educational batch from batch_education_and_academic_learning.csv}';

    protected $description = 'Configures and syncs education-focused categories (World History, English & Language Learning, Mathematics, Biology, etc.)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $prune = (bool) $this->option('prune-non-education');
        $importBatch = (bool) $this->option('import-batch');

        $this->info($dryRun ? '=== [DRY RUN] Education Categories Audit ===' : '=== Updating Education Categories ===');

        // 1. Education categories to ensure
        $educationCategories = [
            'Education & Reference' => [
                'slug' => 'education',
                'children' => [
                    'world-history' => 'World History',
                    'mathematics' => 'Mathematics',
                    'biology' => 'Biology & Life Sciences',
                    'computers' => 'Computers & programming',
                    'other-languages' => 'Other languages',
                    'study-skills' => 'Study skills',
                    'reference' => 'Reference',
                ],
            ],
            'English Learning' => [
                'slug' => 'english-learning',
                'children' => [
                    'ielts-toefl' => 'IELTS & TOEFL',
                    'oxford-box' => 'Oxford Box & Readers',
                    'english-coursebooks' => 'Coursebooks (Oxford, Cambridge…)',
                    'grammar' => 'Grammar',
                    'vocabulary' => 'Vocabulary',
                    'graded-readers' => 'Graded readers',
                    'dictionaries' => 'Dictionaries',
                ],
            ],
            'Khmer Education' => [
                'slug' => 'khmer-school',
                'children' => [
                    'grade-7' => 'Grade 7',
                    'grade-8' => 'Grade 8',
                    'grade-9' => 'Grade 9',
                    'grade-10' => 'Grade 10',
                    'grade-11' => 'Grade 11',
                    'grade-12' => 'Grade 12',
                    'exam-prep' => 'Exam preparation',
                ],
            ],
        ];

        $this->line("\n<comment>1. Verifying Core Education Categories:</comment>");
        foreach ($educationCategories as $parentName => $data) {
            $parent = Category::where('slug', $data['slug'])->first();
            $status = $parent ? "<fg=green>EXISTS</> (ID: {$parent->id})" : '<fg=yellow>MISSING</>';
            $this->line("  [ROOT] {$parentName} ({$data['slug']}): {$status}");

            foreach ($data['children'] as $childSlug => $childName) {
                $child = Category::where('slug', $childSlug)->first();
                if ($child) {
                    $this->line("    └─ {$childName} ({$childSlug}): <fg=green>READY</> (ID: {$child->id})");
                } else {
                    $this->line("    └─ {$childName} ({$childSlug}): <fg=yellow>TO BE CREATED</>");
                    if (! $dryRun && $parent) {
                        Category::create([
                            'name' => $childName,
                            'slug' => $childSlug,
                            'parent_id' => $parent->id,
                            'sort_order' => 50,
                            'is_visible' => true,
                        ]);
                        $this->info('       -> Created successfully.');
                    }
                }
            }
        }

        // 2. Non-educational categories identified for pruning
        $nonEducationSlugs = ['romance', 'ya-romance'];
        $this->line("\n<comment>2. Non-Educational Categories Targeted for Removal:</comment>");
        $targets = Category::whereIn('slug', $nonEducationSlugs)->get();

        if ($targets->isEmpty()) {
            $this->line('  No non-educational categories found matching target list.');
        } else {
            foreach ($targets as $cat) {
                $bookCount = $cat->books()->count();
                $this->line("  - {$cat->name} ({$cat->slug}): {$bookCount} books currently linked.");
                if ($prune && ! $dryRun) {
                    $cat->delete();
                    $this->warn("    -> Soft-deleted category '{$cat->name}'.");
                } elseif (! $prune) {
                    $this->line('    (Pass --prune-non-education to deactivate)');
                }
            }
        }

        // 3. Batch import from batch_education_and_academic_learning.csv
        $batchPath = base_path('data/catalog/batch_education_and_academic_learning.csv');
        if (! File::exists($batchPath)) {
            $batchPath = dirname(base_path()).'/batch_education_and_academic_learning.csv';
        }

        if (File::exists($batchPath)) {
            $this->line("\n<comment>3. Educational Books Batch ({$batchPath}):</comment>");
            $handle = fopen($batchPath, 'r');
            $header = fgetcsv($handle, null, ',', '"', '');
            $columns = array_map(fn ($c) => strtolower(trim((string) $c)), $header ?: []);
            $categoriesByName = Category::all()->groupBy(fn (Category $c) => mb_strtolower($c->name));

            $count = 0;
            $created = 0;
            $skipped = 0;

            while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($cells === [null] || implode('', array_map('trim', array_map('strval', $cells))) === '') {
                    continue;
                }
                $row = [];
                foreach ($columns as $i => $column) {
                    $row[$column] = trim((string) ($cells[$i] ?? ''));
                }
                $title = $row['title'] ?? '';
                if ($title === '') {
                    continue;
                }
                $count++;

                $sku = $row['sku'] ?? '';
                $isbn = ! empty($row['isbn']) ? strtoupper(preg_replace('/[^0-9X]/i', '', $row['isbn'])) : null;
                $rawAuthors = $row['authors'] ?? '';
                $authorList = array_filter(array_map('trim', explode(';', $rawAuthors)));
                $firstAuthor = $authorList[0] ?? '';
                $formatStr = strtolower($row['format'] ?? 'paperback');
                $format = $formatStr === 'hardcover' ? BookFormat::Hardcover : BookFormat::Paperback;

                $exists = false;
                if ($sku !== '') {
                    $exists = BookVariant::whereRaw('lower(sku) = ?', [mb_strtolower($sku)])->exists();
                }
                if (! $exists && $isbn !== null) {
                    $exists = BookVariant::whereRaw("upper(regexp_replace(coalesce(isbn, ''), '[^0-9Xx]', '', 'g')) = ?", [$isbn])->exists();
                }
                if (! $exists && $firstAuthor !== '') {
                    $exists = Book::where('title', $title)
                        ->whereHas('authors', fn ($q) => $q->whereRaw('lower(name) = ?', [mb_strtolower($firstAuthor)]))
                        ->exists();
                }

                if ($exists) {
                    $skipped++;
                    $this->line("  - [EXISTS] {$title}");

                    continue;
                }

                $this->line("  - [PENDING] {$title} by {$firstAuthor}");
                if ($importBatch && ! $dryRun) {
                    $publisherId = null;
                    if (! empty($row['publisher'])) {
                        $pub = Publisher::firstOrCreate(['name' => $row['publisher']]);
                        $publisherId = $pub->id;
                    }

                    $seriesId = null;
                    if (! empty($row['series'])) {
                        $ser = Series::whereRaw('lower(name) = ?', [mb_strtolower($row['series'])])->first()
                            ?? Series::create(['name' => $row['series']]);
                        $seriesId = $ser->id;
                    }

                    $book = Book::create([
                        'title' => $title,
                        'description' => ! empty($row['description']) ? $row['description'] : null,
                        'status' => 'live',
                        'publisher_id' => $publisherId,
                        'series_id' => $seriesId,
                        'series_order' => ! empty($row['series_order']) ? (int) $row['series_order'] : null,
                        'language' => ! empty($row['language']) ? $row['language'] : 'English',
                        'page_count' => ! empty($row['page_count']) ? (int) $row['page_count'] : null,
                        'publish_date' => ! empty($row['publish_date']) ? $row['publish_date'] : null,
                    ]);

                    if ($authorList !== []) {
                        $authorIds = [];
                        foreach ($authorList as $authorName) {
                            $auth = Author::whereRaw('lower(name) = ?', [mb_strtolower($authorName)])->first()
                                ?? Author::create(['name' => $authorName]);
                            $authorIds[] = $auth->id;
                        }
                        $book->authors()->sync(array_values(array_unique($authorIds)));
                    }

                    if (! empty($row['categories'])) {
                        $catIds = [];
                        foreach (array_filter(array_map('trim', explode(';', $row['categories']))) as $catName) {
                            $matched = $categoriesByName->get(mb_strtolower($catName));
                            if ($matched && $matched->isNotEmpty()) {
                                $catIds[] = $matched->first()->id;
                            }
                        }
                        if ($catIds !== []) {
                            $book->categories()->sync(array_values(array_unique($catIds)));
                        }
                    }

                    $priceUsd = ! empty($row['price_usd']) && is_numeric($row['price_usd']) ? (float) $row['price_usd'] : 15.00;
                    $stock = isset($row['stock_quantity']) && is_numeric($row['stock_quantity']) ? (int) $row['stock_quantity'] : 25;

                    BookVariant::create([
                        'book_id' => $book->id,
                        'format' => $format,
                        'sku' => $sku !== '' ? $sku : 'EDU-'.strtoupper(substr(md5($title), 0, 8)),
                        'isbn' => $isbn,
                        'price_usd' => $priceUsd,
                        'stock_quantity' => $stock,
                        'low_stock_threshold' => 5,
                        'is_active' => true,
                        'cover_image_url' => ! empty($row['cover_image_url']) ? $row['cover_image_url'] : null,
                    ]);

                    $created++;
                }
            }
            fclose($handle);
            $this->info("  Total in batch: {$count} | Already in DB: {$skipped} | Imported: {$created}");
            if (! $importBatch && $count > $skipped) {
                $this->line('  (Pass --import-batch to import all pending books into the database)');
            }
        }

        $this->newLine();
        $this->info($dryRun ? 'Dry run completed. No database changes were saved.' : 'Education categories & catalog successfully configured!');

        return Command::SUCCESS;
    }
}

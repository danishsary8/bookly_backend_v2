<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The starter category tree (owner 2026-11-05: every kind of book; staff can rename, move or add more later).
 * Installing it never removes or renames an existing category: a category whose slug is already there is reused
 * (and placed under its parent only if it has none yet), everything else is added. Categories that are not in this
 * list stay where they are.
 */
class StarterCategories
{
    /** @var array<string, array{name: string, icon: string, children: array<string, string>}> slug => parent */
    public const TREE = [
        'fiction' => ['name' => 'Fiction', 'icon' => 'book-open', 'children' => [
            'literary-fiction' => 'Literary fiction', 'classics' => 'Classics', 'romance' => 'Romance', 'fantasy' => 'Fantasy',
            'science-fiction' => 'Science fiction', 'mystery' => 'Mystery & thriller', 'horror' => 'Horror',
            'historical-fiction' => 'Historical fiction', 'adventure' => 'Adventure', 'short-stories' => 'Short stories', 'poetry' => 'Poetry',
        ]],
        'khmer-books' => ['name' => 'Khmer books', 'icon' => 'languages', 'children' => [
            'khmer-novels' => 'Khmer novels', 'khmer-short-stories' => 'Khmer short stories', 'khmer-poetry' => 'Khmer poetry',
            'khmer-folktales' => 'Khmer folktales', 'khmer-history-culture' => 'Khmer history & culture',
        ]],
        'khmer-school' => ['name' => 'Khmer school books', 'icon' => 'school', 'children' => [
            'grade-7' => 'Grade 7', 'grade-8' => 'Grade 8', 'grade-9' => 'Grade 9', 'grade-10' => 'Grade 10',
            'grade-11' => 'Grade 11', 'grade-12' => 'Grade 12', 'exam-prep' => 'Exam preparation',
        ]],
        'english-learning' => ['name' => 'English learning', 'icon' => 'graduation-cap', 'children' => [
            'english-coursebooks' => 'Coursebooks (Oxford, Cambridge…)', 'grammar' => 'Grammar', 'vocabulary' => 'Vocabulary',
            'graded-readers' => 'Graded readers', 'ielts-toefl' => 'IELTS & TOEFL', 'dictionaries' => 'Dictionaries',
        ]],
        'self-help' => ['name' => 'Self-help & growth', 'icon' => 'sprout', 'children' => [
            'self-improvement' => 'Self-improvement', 'personal-development' => 'Personal development', 'productivity' => 'Productivity',
            'psychology' => 'Psychology', 'mindfulness' => 'Mindfulness', 'relationships' => 'Relationships',
        ]],
        'business' => ['name' => 'Business & money', 'icon' => 'briefcase', 'children' => [
            'entrepreneurship' => 'Entrepreneurship', 'leadership' => 'Leadership', 'marketing' => 'Marketing & sales',
            'finance-investing' => 'Finance & investing', 'economics' => 'Economics',
        ]],
        'non-fiction' => ['name' => 'Non-fiction', 'icon' => 'landmark', 'children' => [
            'history' => 'History', 'biography' => 'Biography & memoir', 'science' => 'Science', 'philosophy' => 'Philosophy',
            'society-politics' => 'Society & politics', 'religion' => 'Religion & spirituality', 'health' => 'Health & fitness',
            'cooking' => 'Cooking', 'travel' => 'Travel', 'true-crime' => 'True crime',
        ]],
        'comics-manga' => ['name' => 'Comics & manga', 'icon' => 'zap', 'children' => [
            'manga' => 'Manga', 'marvel' => 'Marvel', 'dc-comics' => 'DC', 'manhwa-webtoon' => 'Manhwa & webtoon', 'graphic-novels' => 'Graphic novels',
        ]],
        'kids' => ['name' => 'Kids', 'icon' => 'baby', 'children' => [
            'baby-toddler' => 'Baby & toddler (0–3)', 'picture-books' => 'Picture books (3–7)', 'early-readers' => 'Early readers (5–8)',
            'middle-grade' => 'Middle grade (8–12)', 'activity-books' => 'Activity & colouring',
        ]],
        'young-adult' => ['name' => 'Young adult', 'icon' => 'sparkles', 'children' => [
            'ya-fiction' => 'YA fiction', 'ya-fantasy' => 'YA fantasy', 'ya-romance' => 'YA romance',
        ]],
        'education' => ['name' => 'Education & reference', 'icon' => 'library', 'children' => [
            'mathematics' => 'Mathematics', 'computers' => 'Computers & programming', 'other-languages' => 'Other languages',
            'study-skills' => 'Study skills', 'reference' => 'Reference',
        ]],
        'arts-hobbies' => ['name' => 'Arts & hobbies', 'icon' => 'palette', 'children' => [
            'art-design' => 'Art & design', 'photography' => 'Photography', 'music' => 'Music', 'crafts' => 'Crafts & DIY', 'sports' => 'Sports',
        ]],
    ];

    /** Adds what is missing. Safe to run again. Returns how many categories were added. */
    public static function install(): int
    {
        $added = 0;
        $now = now();
        $parentOrder = 0;
        foreach (self::TREE as $parentSlug => $parent) {
            $parentOrder += 10;
            $parentId = self::ensure($parentSlug, $parent['name'], null, $parentOrder, $parent['icon'], $now, $added);
            $childOrder = 0;
            foreach ($parent['children'] as $slug => $name) {
                $childOrder += 10;
                self::ensure($slug, $name, $parentId, $childOrder, null, $now, $added);
            }
        }

        return $added;
    }

    private static function ensure(string $slug, string $name, ?int $parentId, int $order, ?string $icon, $now, int &$added): int
    {
        $existing = DB::table('categories')->where('slug', $slug)->first();
        if ($existing !== null) {
            $changes = [];
            // Only fill gaps: never rename, never move a category staff already placed, never re-parent a top-level one
            // that has children of its own (the tree stays two levels).
            if ($parentId !== null && property_exists($existing, 'parent_id') && $existing->parent_id === null && $existing->id !== $parentId
                && ! DB::table('categories')->where('parent_id', $existing->id)->exists()) {
                $changes['parent_id'] = $parentId;
                $changes['sort_order'] = $order;
            }
            if ($icon !== null && property_exists($existing, 'icon') && $existing->icon === null) {
                $changes['icon'] = $icon;
            }
            if (property_exists($existing, 'deleted_at') && $existing->deleted_at !== null) {
                $changes['deleted_at'] = null;
            }
            if ($changes !== []) {
                DB::table('categories')->where('id', $existing->id)->update([...$changes, 'updated_at' => $now]);
            }

            return (int) $existing->id;
        }
        $added++;

        return (int) DB::table('categories')->insertGetId([
            'name' => $name, 'slug' => $slug, 'parent_id' => $parentId, 'sort_order' => $order, 'icon' => $icon,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
}

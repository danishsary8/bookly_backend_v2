<?php

namespace App\Services\Assistant;

use App\Models\Category;

class BookQueryParser
{
    /** Parse only filters supported by the existing catalogue endpoint. */
    public function parse(string $question): array
    {
        $text = app(QuestionText::class)->normalize($question);
        $text = strtr($text, ['០' => '0', '១' => '1', '២' => '2', '៣' => '3', '៤' => '4', '៥' => '5', '៦' => '6', '៧' => '7', '៨' => '8', '៩' => '9']);
        $filters = ['per_page' => 5];
        if (preg_match('/(?:under|below|less than|ក្រោម)\s*\$?\s*(\d+(?:\.\d{1,2})?)/u', $text, $match)) {
            [$whole, $fraction] = array_pad(explode('.', $match[1], 2), 2, '');
            $cents = min(100000000, (int) $whole * 100 + (int) str_pad($fraction, 2, '0'));
            $filters['max_price'] = number_format(max(0, $cents - 1) / 100, 2, '.', '');
            $text = str_replace($match[0], ' ', $text);
        }
        if (preg_match('/(?:grade|ថ្នាក់ទី|ថ្នាក់)\s*(1[0-2]|[1-9])\b/u', $text, $match)) {
            $filters['school_grade'] = (int) $match[1];
            $text = str_replace($match[0], ' ', $text);
        }
        if (preg_match('/\b([abc][12])\b/u', $text, $match)) {
            $filters['level'] = strtoupper($match[1]);
            $text = str_replace($match[0], ' ', $text);
        }
        if (preg_match('/\b(?:ebook|e book|ebooks)\b|សៀវភៅអេឡិចត្រូនិក/u', $text, $match)) {
            $filters['has_ebook'] = 1;
            $text = str_replace($match[0], ' ', $text);
        } elseif (preg_match('/\b(?:paperback|hardcover|audiobook)\b/u', $text, $match)) {
            $filters['format'] = $match[0];
            $text = str_replace($match[0], ' ', $text);
        }
        $categories = Category::query()->get(['id', 'name', 'slug'])->sortByDesc(fn ($category) => mb_strlen($category->name));
        $aliases = ['ប្រលោមលោកខ្មែរ' => 'khmer-novels', 'តុក្កតា' => 'manga', 'ប្រលោមលោក' => 'fiction', 'សៀវភៅកុមារ' => 'kids', 'comics' => 'comics-manga'];
        foreach ($aliases as $word => $slug) {
            if (str_contains($text, $word) && ($category = $categories->firstWhere('slug', $slug))) {
                $filters['category_id'] = $category->id;
                $text = str_replace($word, ' ', $text);
                break;
            }
        }
        if (! isset($filters['category_id'])) {
            foreach ($categories as $category) {
                $name = app(QuestionText::class)->normalize($category->name);
                if (mb_strlen($name) >= 3 && preg_match('/(?<![a-z0-9])'.preg_quote($name, '/').'(?![a-z0-9])/u', $text)) {
                    $filters['category_id'] = $category->id;
                    $text = str_replace($name, ' ', $text);
                    break;
                }
            }
        }
        if (preg_match('/\bkhmer\b|ភាសាខ្មែរ|ខ្មែរ/u', $text, $match)) {
            $filters['language'] = 'Khmer';
            $text = str_replace($match[0], ' ', $text);
        } elseif (preg_match('/\benglish\b/u', $text) && ! str_contains($text, 'english file')) {
            $filters['language'] = 'English';
            $text = preg_replace('/\benglish\b/u', ' ', $text);
        }
        $text = preg_replace('/\b(?:find|search|show|me|for|books|book|please|a|an|the)\b/u', ' ', $text);
        $text = str_replace(['សៀវភៅ', 'សូម', 'ស្វែងរក'], ' ', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text !== '') {
            $filters['q'] = mb_substr($text, 0, 200);
        }

        return $filters;
    }
}

<?php

namespace Tests\Feature\Catalog;

use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KhmerSearchTest extends TestCase
{
    use RefreshDatabase;

    private function createBook(array $attrs = []): Book
    {
        $book = Book::factory()->create(array_merge([
            'status' => 'live',
        ], $attrs));

        BookVariant::factory()->for($book)->create([
            'is_active' => true,
            'stock_quantity' => 10,
        ]);

        return $book;
    }

    public function test_khmer_title_found_by_middle_part_of_phrase(): void
    {
        $book = $this->createBook([
            'title' => 'រឿងព្រេងបុរាណខ្មែរ',
            'description' => 'ការប្រមូលផ្តុំនៃរឿងព្រេងបុរាណ',
        ]);
        $other = $this->createBook([
            'title' => 'សៀវភៅភាសាខ្មែរ',
        ]);

        // Middle substring of title: ព្រេង
        $response = $this->getJson('/api/v1/books?q=ព្រេង');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $book->id)
            ->assertJsonPath('data.0.title', 'រឿងព្រេងបុរាណខ្មែរ');
    }

    public function test_khmer_author_found(): void
    {
        $author = Author::factory()->create(['name' => 'សម្តេចជួនណាត']);
        $book = $this->createBook(['title' => 'វចនានុក្រមខ្មែរ']);
        $book->authors()->attach($author);

        $other = $this->createBook(['title' => 'សៀវភៅទូទៅ']);

        $response = $this->getJson('/api/v1/books?q=ជួនណាត');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $book->id);
    }

    public function test_mixed_khmer_and_latin_query(): void
    {
        $match = $this->createBook([
            'title' => 'Cambodia ប្រវត្តិសាស្ត្រ',
            'description' => 'A history of Cambodia',
        ]);

        $onlyLatin = $this->createBook([
            'title' => 'Cambodia Modern Times',
            'description' => 'Contemporary history',
        ]);

        $onlyKhmer = $this->createBook([
            'title' => 'សៀវភៅ ប្រវត្តិសាស្ត្រ ខ្មែរ',
            'description' => 'Ancient times',
        ]);

        // Both words must match (AND condition)
        $response = $this->getJson('/api/v1/books?q=cambodia+ប្រវត្តិ');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_query_with_zero_width_space(): void
    {
        $book = $this->createBook([
            'title' => 'រឿងព្រេងខ្មែរ',
        ]);

        // Query containing zero-width space U+200B in the middle: រឿង​ព្រេង
        $queryWithZwsp = "រឿង\u{200B}ព្រេង";
        $response = $this->getJson('/api/v1/books?q='.urlencode($queryWithZwsp));

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $book->id);
    }

    public function test_khmer_ranking_ranks_title_matches_first(): void
    {
        // Book A has query in title
        $titleMatch = $this->createBook([
            'title' => 'រឿងព្រេងខ្មែរភាគមួយ',
            'description' => 'សេចក្តីពិពណ៌នាទូទៅ',
        ]);

        // Book B only has query in description
        $descMatch = $this->createBook([
            'title' => 'សៀវភៅអក្សរសិល្ប៍',
            'description' => 'រឿងព្រេងខ្មែរភាគមួយ សម្រាប់កុមារ',
        ]);

        $response = $this->getJson('/api/v1/books?q=រឿងព្រេងខ្មែរ');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $titleMatch->id)
            ->assertJsonPath('data.1.id', $descMatch->id);
    }
}

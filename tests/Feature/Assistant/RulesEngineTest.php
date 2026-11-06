<?php

namespace Tests\Feature\Assistant;

use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Assistant\BookQueryParser;
use App\Services\Assistant\QuestionText;
use App\Services\Assistant\RulesEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RulesEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalises_khmer_zero_width_and_redacts_email_and_phone(): void
    {
        $text = app(QuestionText::class);
        $this->assertSame('សួស្តី', $text->normalize("សួស្តី\u{200B}!?"));
        $this->assertSame('Please contact [email] or [phone]', $text->redact('Please contact reader@example.test or +855 12 345 678'));
        $this->assertSame('[email] [phone]', $text->redact("reader@exam\u{200B}ple.test +855 12\u{200B} 345 678"));
        $this->assertSame('[phone]', $text->redact('០១២ ៣៤៥ ៦៧៨'));
        $this->assertLessThanOrEqual(300, mb_strlen($text->redact(str_repeat('a', 400))));
    }

    public function test_unanswered_questions_are_redacted_and_counted_without_recording_contact_details(): void
    {
        $engine = app(RulesEngine::class);
        $question = 'Unrecognised puzzle reader@example.test +855 12 345 678';
        $this->assertTrue($engine->answer($question, null)['handoff']);
        $engine->answer($question, null);
        $record = DB::table('assistant_unanswered')->first();
        $this->assertSame(2, $record->count);
        $this->assertStringNotContainsString('reader@example.test', $record->question);
        $this->assertStringNotContainsString('345', $record->question);
    }

    public function test_specific_order_lookup_never_discloses_another_customers_order(): void
    {
        $owner = Customer::factory()->create();
        $other = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $other->id, 'status' => 'delivered']);
        $answer = app(RulesEngine::class)->answer('Where is my order '.$order->order_number, $owner);
        $this->assertSame(__('assistant.order_missing'), $answer['text']);
        $this->assertStringNotContainsString($order->order_number, $answer['text']);
        $this->assertSame(__('assistant.order_signin'), app(RulesEngine::class)->answer('my order', null)['text']);
        $this->assertSame(__('assistant.order_missing'), app(RulesEngine::class)->answer('my order #'.$order->id, $owner)['text']);
    }

    public function test_book_related_policy_questions_are_not_mistaken_for_catalogue_searches(): void
    {
        $engine = app(RulesEngine::class);
        $this->assertSame('returns', $engine->answer('Can I return a book?', null)['intent']);
        $this->assertSame('payment', $engine->answer('How do I pay for a book?', null)['intent']);
        $this->assertSame('delivery', $engine->answer('How much is delivery for books?', null)['intent']);
    }

    public function test_catalogue_parser_and_search_use_real_filters(): void
    {
        $category = Category::factory()->create(['name' => 'Manga']);
        $cheap = BookVariant::factory()->create(['price_usd' => '4.50']);
        $cheap->book->categories()->attach($category);
        $expensive = BookVariant::factory()->create(['price_usd' => '7.00']);
        $expensive->book->categories()->attach($category);
        $boundary = BookVariant::factory()->create(['price_usd' => '5.00']);
        $boundary->book->categories()->attach($category);
        $parser = app(BookQueryParser::class);
        $this->assertSame('4.99', $parser->parse('manga under $5')['max_price']);
        $this->assertSame(9, $parser->parse('Khmer grade 9')['school_grade']);
        $this->assertSame(9, $parser->parse('សៀវភៅ ថ្នាក់ទី ៩')['school_grade']);
        $this->assertSame('Khmer', $parser->parse('សៀវភៅខ្មែរ ថ្នាក់ទី ៩')['language']);
        $school = Book::factory()->create(['title' => 'School reader', 'language' => 'Khmer', 'school_grade' => 9]);
        BookVariant::factory()->create(['book_id' => $school->id]);
        $this->assertContains($school->id, array_column(app(RulesEngine::class)->answer('សៀវភៅខ្មែរ ថ្នាក់ទី ៩', null)['books'], 'id'));
        $fantasy = Category::factory()->create(['name' => 'Fantasy']);
        $this->assertSame($fantasy->id, $parser->parse('fantasy books')['category_id']);
        $this->assertSame('B1', $parser->parse('English File B1')['level']);
        $answer = app(RulesEngine::class)->answer('manga under $5', null);
        $this->assertSame([$cheap->book_id], array_column($answer['books'], 'id'));
        $course = Book::factory()->create(['title' => 'English File B1', 'level' => 'B1']);
        BookVariant::factory()->create(['book_id' => $course->id]);
        $this->assertContains($course->id, array_column(app(RulesEngine::class)->answer('English File B1', null)['books'], 'id'));
    }
}

<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantFaq;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StaffUser;
use Database\Seeders\AssistantFaqSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class AssistantApiTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_guest_and_verified_customer_cannot_read_another_customers_order(): void
    {
        $owner = Customer::factory()->create();
        $order = Order::factory()->create(['customer_id' => $owner->id]);
        $question = 'Track order '.$order->order_number;
        $this->postJson('/api/v1/assistant', ['question' => $question])->assertOk()->assertJsonPath('data.text', __('assistant.order_signin'));
        $other = Customer::factory()->create();
        $this->asToken($other->createToken('test', ['customer'])->plainTextToken);
        $this->postJson('/api/v1/assistant', ['question' => $question])->assertOk()->assertJsonPath('data.text', __('assistant.order_missing'));
        $this->asToken($owner->createToken('test', ['customer'])->plainTextToken);
        $this->postJson('/api/v1/assistant', ['question' => $question])->assertOk()->assertJsonPath('data.link', '/account/orders/'.$order->id);
    }

    public function test_question_limit_and_plain_text_are_enforced(): void
    {
        $this->postJson('/api/v1/assistant', ['question' => '<script>'])->assertUnprocessable();
        for ($i = 0; $i < 19; $i++) {
            $this->postJson('/api/v1/assistant', ['question' => 'Hello'])->assertOk();
        }
        $this->postJson('/api/v1/assistant', ['question' => 'Hello'])->assertStatus(429);
    }

    public function test_staff_answers_are_checked_against_underlying_abilities(): void
    {
        $staff = StaffUser::factory()->withTwoFactor()->create(['permissions' => ['catalog:view']]);
        $this->asToken($staff->createToken('test', $staff->tokenAbilities())->plainTextToken);
        $this->postJson('/api/v1/staff/assistant', ['question' => 'sales today'])->assertOk()->assertJsonPath('data.text', __('assistant.unavailable'));
        $this->postJson('/api/v1/staff/assistant', ['question' => 'orders waiting'])->assertOk()->assertJsonPath('data.text', __('assistant.unavailable'));
        $this->postJson('/api/v1/staff/assistant', ['question' => 'low stock'])->assertOk()->assertJsonPath('data.intent', 'stock')->assertJsonPath('data.link', '/admin/stock');
        $this->getJson('/api/v1/staff/assistant/faqs')->assertForbidden();
    }

    public function test_faq_seed_is_explicit_idempotent_and_bilingual_matching_counts_hits(): void
    {
        $this->seed(AssistantFaqSeeder::class);
        $this->seed(AssistantFaqSeeder::class);
        $this->assertSame(25, AssistantFaq::count());
        $this->withHeader('Accept-Language', 'km')->postJson('/api/v1/assistant', ['question' => 'បញ្ជីអាន'])->assertOk()->assertJsonPath('data.intent', 'faq');
        $this->assertSame(1, AssistantFaq::where('question_en', 'How do reading lists work?')->first()->hits);
    }

    public function test_authorized_staff_can_resolve_a_redacted_question_into_a_faq(): void
    {
        $this->postJson('/api/v1/assistant', ['question' => 'A strange puzzle reader@example.test'])->assertOk();
        $this->asToken($this->staffToken());
        $item = $this->getJson('/api/v1/staff/assistant/unanswered')->assertOk()->json('data.data.0');
        $this->assertStringNotContainsString('reader@example.test', $item['question']);
        $faq = ['question_en' => 'A puzzle?', 'question_km' => 'សំណួរមួយ?', 'answer_en' => 'Please contact our team.', 'answer_km' => 'សូមទាក់ទងក្រុមការងាររបស់យើង។', 'keywords' => ['strange puzzle', 'សំណួរមួយ'], 'is_active' => true, 'unanswered_id' => $item['id']];
        $this->postJson('/api/v1/staff/assistant/faqs', $faq)->assertCreated();
        $this->assertDatabaseMissing('assistant_unanswered', ['id' => $item['id']]);
        $this->postJson('/api/v1/assistant', ['question' => 'strange puzzle'])->assertOk()->assertJsonPath('data.text', $faq['answer_en']);
    }
}

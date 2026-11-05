<?php

namespace Tests\Feature\Support;

use App\Models\Customer;
use App\Models\StaffUser;
use App\Models\SupportConversation;
use App\Services\Support\SupportService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class SupportChatTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config(['services.telegram_bot.token' => null, 'services.turnstile.secret' => '']);
    }

    private function customer(): Customer
    {
        $customer = Customer::factory()->create();
        $this->asToken($customer->createToken('test', ['customer'])->plainTextToken);

        return $customer;
    }

    private function start(): int
    {
        return $this->postJson('/api/v1/support/conversations', ['topic' => 'delivery', 'message' => 'Where is my parcel?'])->assertCreated()->json('data.id');
    }

    public function test_ownership_covers_every_customer_endpoint_and_order_reference(): void
    {
        $this->customer();
        $id = $this->start();
        $this->customer();
        $this->getJson('/api/v1/support/conversations')->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/support/conversations/$id")->assertNotFound();
        foreach (['messages' => ['message' => 'Intrude'], 'read' => [], 'status' => ['status' => 'closed'], 'rating' => ['stars' => 5]] as $path => $payload) {
            $this->postJson("/api/v1/support/conversations/$id/$path", $payload)->assertNotFound();
        }
        $this->postJson('/api/v1/support/conversations', ['topic' => 'order', 'message' => 'Order', 'order_id' => 999999])->assertNotFound();
    }

    public function test_internal_notes_stay_staff_only_and_read_receipts_invalidate_etags(): void
    {
        $owner = $this->customer();
        $token = $owner->createToken('owner', ['customer'])->plainTextToken;
        $id = $this->start();
        $first = $this->getJson("/api/v1/support/conversations/$id")->assertOk();
        $this->withHeader('If-None-Match', $first->headers->get('ETag'))->getJson("/api/v1/support/conversations/$id")->assertStatus(304);
        $this->flushHeaders();
        $this->asToken($this->staffToken());
        $this->postJson("/api/v1/staff/support/conversations/$id/messages", ['message' => 'Private note', 'is_internal' => true])->assertNoContent();
        $this->postJson("/api/v1/staff/support/conversations/$id/messages", ['message' => 'Your parcel is packed.'])->assertNoContent();
        $this->getJson("/api/v1/staff/support/conversations/$id")->assertJsonCount(3, 'data.messages')->assertJsonPath('data.customer.email', $owner->email);
        $this->asToken($token);
        $result = $this->getJson("/api/v1/support/conversations/$id")->assertJsonCount(2, 'data.messages')->assertJsonPath('data.unread_count', 1);
        $this->assertArrayNotHasKey('customer', $result->json('data'));
        $this->assertStringNotContainsString('Private note', $result->getContent());
        $this->postJson("/api/v1/support/conversations/$id/read")->assertNoContent();
        $this->withHeader('If-None-Match', $result->headers->get('ETag'))->getJson("/api/v1/support/conversations/$id")->assertOk()->assertJsonPath('data.unread_count', 0);
    }

    public function test_plain_text_limits_closed_state_and_once_only_rating(): void
    {
        $this->customer();
        $id = $this->start();
        $url = "/api/v1/support/conversations/$id";
        $this->postJson("$url/messages", ['message' => '<script>bad</script>'])->assertUnprocessable();
        $this->postJson("$url/messages", ['message' => str_repeat('x', 2001)])->assertUnprocessable();
        $this->postJson("$url/rating", ['stars' => 5])->assertUnprocessable();
        $this->postJson("$url/status", ['status' => 'pending'])->assertUnprocessable();
        $this->postJson("$url/status", ['status' => 'closed'])->assertNoContent();
        $this->postJson("$url/messages", ['message' => 'More'])->assertUnprocessable();
        $this->postJson("$url/rating", ['stars' => 6])->assertUnprocessable();
        $this->postJson("$url/rating", ['stars' => 5, 'comment' => 'Helpful'])->assertNoContent();
        $this->postJson("$url/rating", ['stars' => 1])->assertUnprocessable();
        $this->getJson($url)->assertJsonPath('data.rating.stars', 5);
    }

    public function test_guest_has_no_read_access_and_is_limited_to_three_submissions_per_ip(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/support/guest', ['name' => 'Guest', 'email' => 'guest@example.test', 'message' => 'Help'])->assertCreated()->assertJsonStructure(['data' => ['id']]);
        }
        $this->postJson('/api/v1/support/guest', ['name' => 'Guest', 'email' => 'guest@example.test', 'message' => 'Help'])->assertStatus(429);
        $this->getJson('/api/v1/support/conversations/1')->assertUnauthorized();
    }

    public function test_guest_turnstile_fails_closed_and_staff_ability_is_required(): void
    {
        config(['services.turnstile.secret' => 'test-only']);
        $this->postJson('/api/v1/support/guest', ['name' => 'Guest', 'email' => 'guest@example.test', 'message' => 'Help'])->assertUnprocessable();
        $staff = StaffUser::factory()->withTwoFactor()->create(['permissions' => ['books:manage']]);
        $this->asToken($staff->createToken('test', ['staff', 'books:manage'])->plainTextToken)->getJson('/api/v1/staff/support/conversations')->assertForbidden();
    }

    public function test_retention_only_anonymises_closed_conversations_older_than_one_year(): void
    {
        $owner = $this->customer();
        $old = app(SupportService::class)->start($owner, ['topic' => 'other', 'message' => 'Personal detail']);
        $old->update(['status' => 'closed', 'closed_at' => now()->subYear()->subDay()]);
        $recent = app(SupportService::class)->start($owner, ['topic' => 'other', 'message' => 'Keep recent']);
        $this->artisan('support:anonymise')->assertSuccessful();
        $this->assertNull($old->fresh()->customer_id);
        $this->assertNotNull($old->fresh()->anonymised_at);
        $this->assertNotEquals('Personal detail', $old->messages()->first()->body);
        $this->assertEquals($owner->id, $recent->fresh()->customer_id);
    }

    public function test_message_quota_and_demo_writes_are_enforced(): void
    {
        $owner = $this->customer();
        $conversation = app(SupportService::class)->start($owner, ['topic' => 'other', 'message' => 'Help']);
        for ($i = 0; $i < 20; $i++) {
            $this->postJson("/api/v1/support/conversations/{$conversation->id}/messages", ['message' => 'Message '.$i])->assertNoContent();
        }
        $this->postJson("/api/v1/support/conversations/{$conversation->id}/messages", ['message' => 'Over limit'])->assertStatus(429);
        $demo = Customer::factory()->create(['email' => DemoSeeder::EMAIL]);
        $this->asToken($demo->createToken('demo', ['customer'])->plainTextToken);
        $this->postJson('/api/v1/support/conversations', ['topic' => 'other', 'message' => 'Demo write'])->assertForbidden();
    }

    public function test_staff_assignment_filters_templates_and_customer_cannot_create_internal_note(): void
    {
        $owner = $this->customer();
        $token = $owner->createToken('owner', ['customer'])->plainTextToken;
        $id = $this->start();
        $this->postJson("/api/v1/support/conversations/$id/messages", ['message' => 'Public', 'is_internal' => true])->assertNoContent();
        $this->assertFalse(SupportConversation::find($id)->messages()->latest('id')->first()->is_internal);
        $staff = StaffUser::factory()->withTwoFactor()->create();
        $this->asToken($staff->createToken('staff', $staff->tokenAbilities())->plainTextToken);
        $this->postJson("/api/v1/staff/support/conversations/$id/assign", ['staff_id' => $staff->id])->assertNoContent();
        $this->getJson('/api/v1/staff/support/conversations?assigned_to_me=1&unread=1&topic=delivery&status=open')->assertJsonCount(1, 'data');
        $template = ['title' => 'Delivery', 'body_en' => 'Your parcel is packed.', 'body_km' => 'បានវេចខ្ចប់កញ្ចប់របស់អ្នកហើយ។'];
        $reply = $this->postJson('/api/v1/staff/support/quick-replies', $template)->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/staff/support/quick-replies/$reply", [...$template, 'title' => 'Updated'])->assertOk();
        $this->deleteJson("/api/v1/staff/support/quick-replies/$reply")->assertNoContent();
        $this->postJson('/api/v1/staff/support/telegram-users', ['staff_id' => $staff->id, 'telegram_user_id' => 42])->assertForbidden();
        $this->asToken($token)->getJson('/api/v1/staff/support/conversations')->assertForbidden();
    }

    public function test_support_resources_match_the_documented_contract(): void
    {
        $this->customer();
        $id = $this->start();
        $schemas = Yaml::parseFile(public_path('openapi.yaml'))['components']['schemas'];
        $result = $this->getJson("/api/v1/support/conversations/$id")->assertOk()->json('data');
        $expected = array_keys($schemas['SupportConversation']['properties']);
        $expected = array_values(array_diff($expected, ['customer']));
        $actual = array_keys($result);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        $expected = array_keys($schemas['SupportMessage']['properties']);
        $actual = array_keys($result['messages'][0]);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
    }
}

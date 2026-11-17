<?php

namespace Tests\Feature\Support;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** Owner 2026-11-17: a staff reply must reach the customer's open chat (no stale 304). */
class SupportReplyVisibilityTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        config(['services.telegram_bot.token' => null, 'services.turnstile.secret' => '']);
    }

    public function test_customer_sees_a_staff_reply_on_the_next_poll(): void
    {
        $customer = Customer::factory()->create();
        $customerToken = $customer->createToken('t', ['customer'])->plainTextToken;
        $staffToken = $this->staffToken();

        $id = $this->asToken($customerToken)->postJson('/api/v1/support/conversations', ['topic' => 'delivery', 'message' => 'Where is my parcel?'])->assertCreated()->json('data.id');
        $first = $this->asToken($customerToken)->getJson("/api/v1/support/conversations/$id")->assertOk();
        $etag = $first->headers->get('ETag');
        $listEtag = $this->asToken($customerToken)->getJson('/api/v1/support/conversations')->headers->get('ETag');

        $this->asToken($staffToken)->getJson("/api/v1/staff/support/conversations/$id")->assertOk();
        $this->asToken($staffToken)->postJson("/api/v1/staff/support/conversations/$id/read")->assertSuccessful();
        $this->asToken($staffToken)->postJson("/api/v1/staff/support/conversations/$id/messages", ['message' => 'It ships today.'])->assertSuccessful();

        $next = $this->asToken($customerToken)->withHeaders(['If-None-Match' => $etag])->getJson("/api/v1/support/conversations/$id");
        $next->assertOk();
        $this->assertContains('It ships today.', array_column($next->json('data.messages'), 'body'));
        $this->assertSame(1, $next->json('data.unread_count'));
        $this->asToken($customerToken)->withHeaders(['If-None-Match' => $listEtag])->getJson('/api/v1/support/conversations')->assertOk()->assertJsonPath('data.0.unread_count', 1);
    }

    public function test_an_unverified_customer_can_chat_and_read_replies_in_the_account(): void
    {
        $customer = Customer::factory()->create(['email_verified_at' => null]);
        $token = $customer->createToken('t', ['customer'])->plainTextToken;

        $id = $this->asToken($token)->postJson('/api/v1/support/conversations', ['topic' => 'other', 'message' => 'Hello?'])->assertCreated()->json('data.id');
        $this->asToken($this->staffToken())->postJson("/api/v1/staff/support/conversations/$id/messages", ['message' => 'Hi, how can we help?'])->assertSuccessful();
        $this->asToken($token)->getJson("/api/v1/support/conversations/$id")->assertOk()->assertJsonPath('data.messages.1.body', 'Hi, how can we help?');
    }
}

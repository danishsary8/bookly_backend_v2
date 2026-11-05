<?php

namespace Tests\Feature\Support;

use App\Mail\SupportReplyMail;
use App\Models\Customer;
use App\Models\StaffUser;
use App\Services\Support\SupportService;
use App\Services\Support\SupportTelegram;
use App\Services\Telegram\TelegramBot;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SupportDeliveryTest extends TestCase
{
    // Real commits exercise afterCommit delivery without sending mail or making any network calls.
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Http::preventStrayRequests();
        config(['services.telegram_bot.token' => null, 'services.turnstile.secret' => '']);
        $this->freezeTime();
    }

    public function test_guest_replies_are_emailed_and_cooldown_replies_are_batched_later(): void
    {
        $service = app(SupportService::class);
        $conversation = $service->start(null, ['name' => 'Guest', 'email' => 'guest@example.test', 'message' => 'Help']);
        Mail::assertSentCount(1);
        $staff = StaffUser::factory()->create();
        $service->message($conversation, 'First answer', $staff);
        Mail::assertSent(SupportReplyMail::class, fn ($mail) => str_contains($mail->messageBody, 'First answer'));
        $service->message($conversation, 'Second answer', $staff);
        $service->message($conversation, 'Private note', $staff, true);
        Mail::assertSentCount(2);
        $this->travel(31)->minutes();
        $this->artisan('support:send-emails')->assertSuccessful();
        Mail::assertSentCount(3);
        Mail::assertSent(SupportReplyMail::class, fn ($mail) => str_contains($mail->messageBody, 'Second answer') && ! str_contains($mail->messageBody, 'Private note'));
        $this->artisan('support:send-emails')->assertSuccessful();
        Mail::assertSentCount(3);
    }

    public function test_present_customer_does_not_receive_email_until_away_and_unread(): void
    {
        $service = app(SupportService::class);
        $customer = Customer::factory()->create();
        $conversation = $service->start($customer, ['topic' => 'other', 'message' => 'Help']);
        $service->message($conversation, 'Hello', StaffUser::factory()->create());
        Mail::assertNothingSent();
        $this->travel(2)->minutes();
        $this->artisan('support:send-emails')->assertSuccessful();
        Mail::assertSentCount(1);
        $service->markRead($conversation, false);
        $this->travel(31)->minutes();
        $this->artisan('support:send-emails')->assertSuccessful();
        Mail::assertSentCount(1);
    }

    public function test_telegram_requires_secret_group_mapping_and_active_linked_staff_and_deduplicates(): void
    {
        $conversation = app(SupportService::class)->start(Customer::factory()->create(), ['topic' => 'other', 'message' => 'Help']);
        config(['services.telegram_bot.token' => 'test-only', 'services.ops_alerts.staff_chat_id' => '-123']);
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 90]])]);
        app(SupportTelegram::class)->alert($conversation);
        Http::assertSent(fn ($request) => $request['chat_id'] === '-123' && ! str_contains($request['text'], 'Help'));
        $staff = StaffUser::factory()->create();
        $staff->forceFill(['support_telegram_user_id' => 42])->save();
        $payload = ['update_id' => 100, 'message' => ['chat' => ['id' => -123, 'type' => 'supergroup'], 'from' => ['id' => 42], 'reply_to_message' => ['message_id' => 90], 'text' => 'Staff reply']];
        $this->postJson('/api/v1/telegram/webhook', $payload)->assertNotFound();
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', app(TelegramBot::class)->webhookSecret());
        $wrong = $payload;
        $wrong['message']['from']['id'] = 999;
        $this->postJson('/api/v1/telegram/webhook', $wrong)->assertNoContent();
        $wrong = $payload;
        $wrong['message']['chat']['id'] = -999;
        $this->postJson('/api/v1/telegram/webhook', $wrong)->assertNoContent();
        $this->assertEquals(1, $conversation->messages()->count());
        $this->postJson('/api/v1/telegram/webhook', $payload)->assertNoContent();
        $this->postJson('/api/v1/telegram/webhook', $payload)->assertNoContent();
        $this->assertEquals(2, $conversation->messages()->count());
        $this->assertEquals($staff->id, $conversation->messages()->latest('id')->first()->staff_id);
        $conversation->update(['status' => 'closed', 'closed_at' => now()]);
        $payload['update_id'] = 101;
        $this->postJson('/api/v1/telegram/webhook', $payload)->assertNoContent();
        $this->assertEquals(2, $conversation->messages()->count());
        $this->assertEquals(1, DB::table('support_telegram_updates')->count());
    }
}

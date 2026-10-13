<?php

namespace Tests\Feature\Platform;

use App\Models\Customer;
use App\Notifications\WelcomeNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class WelcomeAndReviewAlertsTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_verifying_the_email_sends_one_welcome(): void
    {
        $customer = Customer::factory()->unverified()->create(['email' => 'dara@example.com']);
        Notification::assertNothingSent();

        $customer->forceFill(['email_verified_at' => now()])->save();
        $customer->update(['name' => 'Dara S.']); // saving again later sends nothing more

        Notification::assertSentToTimes($customer, WelcomeNotification::class, 1);
        $mail = (new WelcomeNotification)->toMail($customer->fresh());
        $this->assertSame('Welcome to Bookly', $mail->subject);
    }

    public function test_proving_a_phone_in_telegram_sends_the_welcome_when_there_is_an_email(): void
    {
        $customer = Customer::factory()->unverified()->create(['email' => 'sok@example.com']);

        $customer->markPhoneVerified('+85512345678');

        Notification::assertSentToTimes($customer, WelcomeNotification::class, 1);
    }

    public function test_an_account_created_already_verified_gets_one_welcome(): void
    {
        $customer = Customer::factory()->create(); // e.g. Google with a verified email

        Notification::assertSentToTimes($customer, WelcomeNotification::class, 1);
    }

    public function test_unfinished_sign_ups_and_phone_only_accounts_get_no_email(): void
    {
        Customer::factory()->unverified()->create();
        $phoneOnly = Customer::factory()->unverified()->create(['email' => null]);
        $phoneOnly->markPhoneVerified('+85598765432');

        Notification::assertNothingSent();
    }

    public function test_no_welcome_when_the_verification_is_rolled_back(): void
    {
        $customer = Customer::factory()->unverified()->create();

        try {
            DB::transaction(function () use ($customer) {
                $customer->forceFill(['email_verified_at' => now()])->save();
                throw new RuntimeException('something later failed');
            });
        } catch (RuntimeException) {
        }

        Notification::assertNothingSent();
    }

    private function alerts(): void
    {
        config(['services.ops_alerts.token' => '123:alerts', 'services.ops_alerts.chat_id' => '777']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    }

    /** @return array{0: string, 1: int} customer token and the delivered book's id */
    private function buyer(): array
    {
        $customer = Customer::factory()->create(['name' => 'Dara']);
        $variant = $this->physical('12.00');
        $variant->book->update(['title' => 'The Hound of the Baskervilles']);
        $this->deliveredOrder($customer, [[$variant, 1]]);

        return [$customer->createToken('t', ['customer'])->plainTextToken, $variant->book_id];
    }

    public function test_a_one_or_two_star_review_alerts_the_owner(): void
    {
        $this->alerts();
        config(['services.ops_alerts.staff_chat_id' => '-100555']); // reviews are for the owner only
        [$token, $bookId] = $this->buyer();

        $this->withToken($token)->postJson("/api/v1/books/{$bookId}/reviews", ['rating' => 1, 'comment' => 'The cover was torn.'])->assertCreated();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['chat_id'] === '777'
            && str_contains($r['text'], '★☆☆☆☆')
            && str_contains($r['text'], 'The Hound of the Baskervilles')
            && str_contains($r['text'], 'Dara')
            && str_contains($r['text'], 'The cover was torn.'));
    }

    public function test_good_reviews_send_nothing(): void
    {
        $this->alerts();
        [$token, $bookId] = $this->buyer();

        $this->withToken($token)->postJson("/api/v1/books/{$bookId}/reviews", ['rating' => 3, 'comment' => 'Fine.'])->assertCreated();

        Http::assertNothingSent();
    }

    public function test_editing_down_to_two_stars_alerts_once_and_further_edits_do_not(): void
    {
        $this->alerts();
        [$token, $bookId] = $this->buyer();
        $id = $this->withToken($token)->postJson("/api/v1/books/{$bookId}/reviews", ['rating' => 5])->assertCreated()->json('data.id');

        $this->withToken($token)->patchJson("/api/v1/reviews/{$id}", ['rating' => 2])->assertOk();
        $this->withToken($token)->patchJson("/api/v1/reviews/{$id}", ['rating' => 1, 'comment' => 'Worse on a second read.'])->assertOk();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => str_contains($r['text'], 'Review changed to ★★☆☆☆'));
    }

    public function test_a_telegram_outage_never_blocks_the_review(): void
    {
        config(['services.ops_alerts.token' => '123:alerts', 'services.ops_alerts.chat_id' => '777']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false], 500)]);
        [$token, $bookId] = $this->buyer();

        $this->withToken($token)->postJson("/api/v1/books/{$bookId}/reviews", ['rating' => 1])->assertCreated();
    }
}

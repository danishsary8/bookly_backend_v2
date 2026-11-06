<?php

namespace Tests\Feature\Credit;

use App\Mail\GiftCardMail;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Services\Credit\GiftCode;
use App\Services\Credit\GiftDelivery;
use App\Services\Credit\GiftPurchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class GiftCardApiTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        config(['shop.gift_cards_enabled' => true, 'payments.khqr.mode' => 'test', 'payments.khqr.enabled' => true, 'payments.khqr.account_id' => 'bookly@devb', 'payments.khqr.merchant_name' => 'Bookly', 'payments.khqr.city' => 'Phnom Penh', 'mail.default' => 'array']);
    }

    private function card(Customer $buyer): GiftCard
    {
        $id = app(GiftPurchase::class)->start($buyer, 1000, 'Reader', 'reader@example.test', '<script>alert(1)</script>', now(config('shop.timezone'))->toDateString(), 'purchase-api-1');
        $card = GiftCard::findOrFail($id);
        $card->update(['status' => 'active', 'paid_at' => now(), 'expires_at' => now()->addYears(2)]);

        return $card;
    }

    public function test_disabled_routes_return_not_found_and_flags_report_false(): void
    {
        config(['shop.gift_cards_enabled' => false, 'shop.referrals_enabled' => false]);
        $this->getJson('/api/v1/shop/features')->assertOk()->assertJsonPath('data.gift_cards', false);
        $this->getJson('/api/v1/me/gift-cards')->assertNotFound();
        $this->postJson('/api/v1/me/gift-cards/redeem', ['gift_code' => 'invalid'])->assertNotFound();
        $this->getJson('/api/v1/staff/gift-cards')->assertNotFound();
    }

    public function test_buyer_reveals_once_and_another_customer_cannot_read_it(): void
    {
        $buyer = Customer::factory()->create();
        $card = $this->card($buyer);
        $this->asToken($buyer->createToken('t', ['customer'])->plainTextToken);
        $this->getJson('/api/v1/me/gift-cards')->assertOk()->assertJsonMissingPath('data.0.code_hash');
        $this->postJson('/api/v1/me/gift-cards/'.$card->id.'/reveal')->assertOk()->assertJsonPath('data.code', app(GiftCode::class)->forId($card->id));
        $this->postJson('/api/v1/me/gift-cards/'.$card->id.'/reveal')->assertUnprocessable();
        $other = Customer::factory()->create();
        $this->asToken($other->createToken('t', ['customer'])->plainTextToken);
        $this->getJson('/api/v1/me/gift-cards/'.$card->id.'/payment')->assertNotFound();
        $this->postJson('/api/v1/me/gift-cards/'.$card->id.'/reveal')->assertNotFound();
    }

    public function test_redemption_customer_throttle_and_generic_error(): void
    {
        $buyer = Customer::factory()->create();
        $this->asToken($buyer->createToken('t', ['customer'])->plainTextToken);
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/v1/me/gift-cards/redeem', ['gift_code' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('gift_code');
        }
        $this->postJson('/api/v1/me/gift-cards/redeem', ['gift_code' => 'invalid'])->assertStatus(429);
    }

    public function test_due_mail_is_claimed_once_and_message_is_escaped(): void
    {
        $card = $this->card(Customer::factory()->create());
        $delivery = app(GiftDelivery::class);
        $delivery->send($card->id);
        $delivery->send($card->id);
        Mail::assertSent(GiftCardMail::class, 1);
        $this->assertNotNull($card->fresh()->email_sent_at);
        $html = (new GiftCardMail('Reader', '10.00', 'ABCDEFGHJKLMNPQR', '2028-11-06', '<script>alert(1)</script>'))->render();
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_ip_throttle_spans_different_customers(): void
    {
        foreach (range(1, 4) as $customer) {
            $buyer = Customer::factory()->create();
            $this->asToken($buyer->createToken('t', ['customer'])->plainTextToken);
            foreach (range(1, 5) as $attempt) {
                $this->postJson('/api/v1/me/gift-cards/redeem', ['gift_code' => 'invalid'])->assertUnprocessable();
            }
        }
        $buyer = Customer::factory()->create();
        $this->asToken($buyer->createToken('t', ['customer'])->plainTextToken);
        $this->postJson('/api/v1/me/gift-cards/redeem', ['gift_code' => 'invalid'])->assertStatus(429);
    }

    public function test_feature_contract_and_redeemed_wallet_liability_are_preserved(): void
    {
        $buyer = Customer::factory()->create();
        $card = $this->card($buyer);
        $this->asToken($buyer->createToken('t', ['customer'])->plainTextToken);
        $response = $this->getJson('/api/v1/me/gift-cards')->assertOk()->json('data.0');
        $schema = Yaml::parseFile(public_path('openapi.yaml'))['components']['schemas']['GiftCard'];
        $actual = array_keys($response);
        $expected = array_keys($schema['properties']);
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
        $this->postJson('/api/v1/me/gift-cards/redeem', ['gift_code' => app(GiftCode::class)->forId($card->id)])->assertOk();
        $this->asToken($this->staffToken(admin: true));
        $this->getJson('/api/v1/staff/gift-cards')->assertOk()->assertJsonPath('totals.outstanding_usd', '10.00')->assertJsonPath('totals.redeemed_usd', '10.00');
    }

    public function test_staff_without_admin_ability_cannot_manage_money(): void
    {
        $this->asToken($this->staffToken());
        $this->getJson('/api/v1/staff/gift-cards')->assertForbidden();
    }

    public function test_future_unpaid_and_cancelled_cards_are_not_delivered(): void
    {
        $card = $this->card(Customer::factory()->create());
        $card->update(['send_on' => now()->addDay()->toDateString()]);
        app(GiftDelivery::class)->send($card->id);
        $card->update(['send_on' => today()->toDateString(), 'status' => 'pending_payment']);
        app(GiftDelivery::class)->send($card->id);
        $card->update(['status' => 'cancelled']);
        app(GiftDelivery::class)->send($card->id);
        Mail::assertNothingSent();
    }

    public function test_admin_cancel_is_audited_and_never_allows_redeemed_cards(): void
    {
        $card = $this->card(Customer::factory()->create());
        $this->asToken($this->staffToken(admin: true));
        $this->getJson('/api/v1/staff/gift-cards?last_four='.$card->last_four)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('totals.outstanding_usd', '10.00');
        $this->postJson('/api/v1/staff/gift-cards/'.$card->id.'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('admin_audit_logs', ['action' => 'gift_card.cancelled', 'entity_id' => $card->id]);
        $card->update(['status' => 'redeemed']);
        $this->postJson('/api/v1/staff/gift-cards/'.$card->id.'/cancel')->assertUnprocessable();
    }
}

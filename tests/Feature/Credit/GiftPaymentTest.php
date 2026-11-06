<?php

namespace Tests\Feature\Credit;

use App\Models\Customer;
use App\Services\Credit\GiftPayment;
use App\Services\Credit\GiftPurchase;
use App\Services\Payments\Khqr\BakongClient;
use App\Services\Payments\Khqr\BakongUnavailable;
use App\Services\Telegram\OpsAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class GiftPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function purchase(Customer $buyer): int
    {
        config(['shop.gift_cards_enabled' => true, 'payments.khqr.enabled' => true, 'payments.khqr.mode' => 'bakong', 'payments.khqr.account_id' => 'bookly@devb', 'payments.khqr.merchant_name' => 'Bookly', 'payments.khqr.city' => 'Phnom Penh', 'payments.khqr.pay_minutes' => 15]);

        return app(GiftPurchase::class)->start($buyer, 2000, 'Reader', 'reader@example.test', null, now(config('shop.timezone'))->toDateString(), 'purchase_123');
    }

    private function payment(?array $transaction, ?string $alert = null): GiftPayment
    {
        $provider = Mockery::mock(BakongClient::class);
        $provider->shouldReceive('checkByMd5')->once()->with(Mockery::pattern('/^[a-f0-9]{32}$/'))->andReturn($transaction);
        $alerts = Mockery::mock(OpsAlerts::class);
        if ($alert) {
            $alerts->shouldReceive($alert)->once()->with(Mockery::type('int'));
        }

        return new GiftPayment($provider, $alerts);
    }

    public function test_only_verified_matching_payment_activates_once(): void
    {
        $buyer = Customer::factory()->create();
        $id = $this->purchase($buyer);
        $service = $this->payment(['amount' => '20.00', 'currency' => 'USD'], 'giftCardPaid');
        $this->assertSame('active', $service->refresh($buyer, $id));
        $this->assertSame('active', $service->refresh($buyer, $id));
        $this->assertDatabaseHas('gift_card_payments', ['gift_card_id' => $id, 'status' => 'succeeded']);
        $this->assertDatabaseCount('credit_ledger_entries', 0);
        $this->assertEqualsWithDelta(now()->addYears(2)->timestamp, strtotime(DB::table('gift_cards')->where('id', $id)->value('expires_at')), 2);
    }

    public function test_wrong_amount_or_currency_never_creates_paid_value(): void
    {
        $buyer = Customer::factory()->create();
        $id = $this->purchase($buyer);
        foreach ([['amount' => '19.99', 'currency' => 'USD'], ['amount' => '20.00', 'currency' => 'KHR']] as $transaction) {
            $this->assertSame('pending_payment', $this->payment($transaction)->refresh($buyer, $id));
        }
        $this->assertDatabaseHas('gift_cards', ['id' => $id, 'paid_at' => null]);
    }

    public function test_late_payment_requires_staff_review_without_activating_credit(): void
    {
        $buyer = Customer::factory()->create();
        $id = $this->purchase($buyer);
        $this->travel(16)->minutes();
        $service = $this->payment(['amount' => '20', 'currency' => 'USD'], 'giftCardLatePayment');
        $this->assertSame('expired', $service->refresh($buyer, $id));
        $this->assertSame('expired', $service->refresh($buyer, $id));
        $this->assertDatabaseHas('gift_card_payments', ['gift_card_id' => $id, 'status' => 'late']);
    }

    public function test_cancelled_card_stays_cancelled_if_payment_arrives(): void
    {
        $buyer = Customer::factory()->create();
        $id = $this->purchase($buyer);
        DB::table('gift_cards')->where('id', $id)->update(['status' => 'cancelled']);
        $this->assertSame('cancelled', $this->payment(['amount' => '20', 'currency' => 'USD'], 'giftCardLatePayment')->refresh($buyer, $id));
    }

    public function test_unpaid_expired_qr_is_closed(): void
    {
        $buyer = Customer::factory()->create();
        $id = $this->purchase($buyer);
        $this->travel(16)->minutes();
        $this->assertSame('expired', $this->payment(null)->refresh($buyer, $id));
        $this->assertDatabaseHas('gift_card_payments', ['gift_card_id' => $id, 'status' => 'expired']);
    }

    public function test_provider_outage_does_not_activate_or_discard_a_pending_payment(): void
    {
        $buyer = Customer::factory()->create();
        $id = $this->purchase($buyer);
        $provider = Mockery::mock(BakongClient::class);
        $provider->shouldReceive('checkByMd5')->once()->andThrow(new BakongUnavailable('unavailable', 'Unavailable'));
        $service = new GiftPayment($provider, Mockery::mock(OpsAlerts::class));
        $this->assertSame('pending_payment', $service->refresh($buyer, $id));
    }

    public function test_test_mode_cannot_activate_a_card(): void
    {
        $buyer = Customer::factory()->create();
        $id = $this->purchase($buyer);
        config(['payments.khqr.mode' => 'test']);
        $service = new GiftPayment(Mockery::mock(BakongClient::class), Mockery::mock(OpsAlerts::class));
        $this->assertSame('pending_payment', $service->refresh($buyer, $id));
    }

    public function test_another_customer_cannot_poll_the_payment(): void
    {
        $id = $this->purchase(Customer::factory()->create());
        $service = new GiftPayment(Mockery::mock(BakongClient::class), Mockery::mock(OpsAlerts::class));
        $this->expectException(NotFoundHttpException::class);
        $service->refresh(Customer::factory()->create(), $id);
    }
}

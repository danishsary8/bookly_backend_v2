<?php

namespace Tests\Feature\Credit;

use App\Models\Customer;
use App\Services\Credit\GiftCode;
use App\Services\Credit\GiftPurchase;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class GiftPurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_starts_unpaid_and_a_retry_does_not_create_another_card(): void
    {
        config(['shop.gift_cards_enabled' => true, 'payments.khqr.enabled' => true, 'payments.khqr.mode' => 'test', 'payments.khqr.account_id' => 'bookly@devb', 'payments.khqr.merchant_name' => 'Bookly', 'payments.khqr.city' => 'Phnom Penh']);
        $buyer = Customer::factory()->create();
        $date = now(config('shop.timezone'))->toDateString();
        $purchase = app(GiftPurchase::class);
        $id = $purchase->start($buyer, 2000, 'Reader', 'reader@example.test', null, $date, 'purchase_123');
        $this->assertSame($id, $purchase->start($buyer, 2000, 'Reader', 'reader@example.test', null, $date, 'purchase_123'));
        $this->assertDatabaseCount('gift_cards', 1);
        $this->assertDatabaseCount('gift_card_payments', 1);
        $this->assertDatabaseHas('gift_cards', ['id' => $id, 'status' => 'pending_payment', 'paid_at' => null]);
        $this->assertDatabaseHas('gift_card_payments', ['gift_card_id' => $id, 'status' => 'pending']);
        $code = app(GiftCode::class)->forId($id);
        $this->assertDatabaseMissing('gift_cards', ['code_hash' => $code]);
        $this->assertDatabaseCount('credit_ledger_entries', 0);
    }

    public function test_invalid_amount_does_not_create_a_payment(): void
    {
        config(['shop.gift_cards_enabled' => true, 'payments.khqr.enabled' => true]);
        try {
            app(GiftPurchase::class)->start(Customer::factory()->create(), 499, 'Reader', 'reader@example.test', null, now(config('shop.timezone'))->toDateString(), 'purchase_123');
            $this->fail('A gift card below the minimum must fail.');
        } catch (DomainException) {
            $this->assertDatabaseCount('gift_cards', 0);
            $this->assertDatabaseCount('gift_card_payments', 0);
        }
    }

    public function test_disabled_purchase_returns_not_found(): void
    {
        config(['shop.gift_cards_enabled' => false]);
        $this->expectException(NotFoundHttpException::class);
        app(GiftPurchase::class)->start(Customer::factory()->create(), 1000, 'Reader', 'reader@example.test', null, today()->toDateString(), 'purchase_123');
    }
}

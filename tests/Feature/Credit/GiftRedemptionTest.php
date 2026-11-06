<?php

namespace Tests\Feature\Credit;

use App\Models\Customer;
use App\Services\Credit\GiftCode;
use App\Services\Credit\GiftRedemption;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class GiftRedemptionTest extends TestCase
{
    use RefreshDatabase;

    private function card(Customer $buyer, string $status): string
    {
        $id = DB::table('gift_cards')->insertGetId([
            'buyer_customer_id' => $buyer->id, 'recipient_name' => 'Reader', 'recipient_email' => 'reader@example.test',
            'send_on' => today(), 'amount_cents' => 2000, 'status' => $status,
            'paid_at' => $status === 'active' ? now() : null,
            'expires_at' => $status === 'active' ? now()->addYears(2) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $code = app(GiftCode::class)->forId($id);
        DB::table('gift_cards')->where('id', $id)->update(['code_hash' => app(GiftCode::class)->fingerprint($code), 'last_four' => substr($code, -4)]);

        return $code;
    }

    public function test_redeem_credits_once_without_storing_the_plaintext_code(): void
    {
        config(['shop.gift_cards_enabled' => true]);
        $customer = Customer::factory()->create()->fresh();
        $code = $this->card($customer, 'active');
        $this->assertSame(2000, app(GiftRedemption::class)->redeem($customer, $code));
        $this->assertDatabaseMissing('gift_cards', ['code_hash' => $code]);
        $this->assertDatabaseHas('gift_cards', ['status' => 'redeemed', 'redeemed_by_customer_id' => $customer->id]);
        $this->assertDatabaseCount('credit_ledger_entries', 1);
        $this->expectException(DomainException::class);
        app(GiftRedemption::class)->redeem($customer, $code);
    }

    public function test_unpaid_card_cannot_be_redeemed(): void
    {
        config(['shop.gift_cards_enabled' => true]);
        $customer = Customer::factory()->create()->fresh();
        $code = $this->card($customer, 'pending_payment');
        try {
            app(GiftRedemption::class)->redeem($customer, $code);
            $this->fail('An unpaid card must fail.');
        } catch (DomainException) {
            $this->assertDatabaseCount('credit_ledger_entries', 0);
        }
    }

    public function test_disabled_feature_is_not_available(): void
    {
        config(['shop.gift_cards_enabled' => false]);
        $this->expectException(NotFoundHttpException::class);
        app(GiftRedemption::class)->redeem(Customer::factory()->create(), 'ABCDEFGHIJKLMNOP');
    }

    public function test_ten_failed_attempts_lock_the_customer_for_one_hour(): void
    {
        config(['shop.gift_cards_enabled' => true]);
        $customer = Customer::factory()->create()->fresh();
        $validCode = $this->card($customer, 'active');
        for ($i = 0; $i < 10; $i++) {
            try {
                app(GiftRedemption::class)->redeem($customer, 'invalid');
                $this->fail('An invalid code must fail.');
            } catch (DomainException $exception) {
                $this->assertSame('Gift card cannot be redeemed.', $exception->getMessage());
            }
        }
        try {
            app(GiftRedemption::class)->redeem($customer, $validCode);
            $this->fail('A locked customer must not redeem.');
        } catch (DomainException $exception) {
            $this->assertSame('Gift card cannot be redeemed.', $exception->getMessage());
        }
        $this->assertDatabaseCount('credit_ledger_entries', 0);
        $this->travel(61)->minutes();
        $this->assertSame(2000, app(GiftRedemption::class)->redeem($customer, $validCode));
        $this->assertDatabaseHas('gift_redemption_attempts', ['customer_id' => $customer->id, 'failures' => 0, 'locked_until' => null]);
    }
}

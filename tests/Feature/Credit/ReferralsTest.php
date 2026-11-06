<?php

namespace Tests\Feature\Credit;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Services\Credit\CreditWallet;
use App\Services\Credit\Referrals;
use App\Services\Customers\UnverifiedCustomers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class ReferralsTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['shop.referrals_enabled' => true, 'shop.referral_friend_discount' => '2.00', 'shop.referral_sharer_credit' => '2.00']);
    }

    private function pair(?Customer $sharer = null): array
    {
        $sharer ??= Customer::factory()->create()->fresh();
        $friend = Customer::factory()->create()->fresh();
        $referrals = app(Referrals::class);
        $profile = $referrals->profile($sharer, 'sharer-device-123456');
        $referrals->attribute($friend, $profile->code, 'friend-device-123456');

        return [$sharer, $friend, DB::table('referrals')->where('friend_customer_id', $friend->id)->value('id')];
    }

    private function qualify(Customer $friend): Order
    {
        $order = $this->deliveredOrder($friend, [[$this->physical(), 1]], deliveredAt: now()->subDays(8));
        $order->update(['referral_discount_cents' => 200]);
        app(Referrals::class)->placed($order);

        return $order;
    }

    public function test_first_order_discount_requires_email_verification_and_ten_dollars(): void
    {
        [, $friend] = $this->pair();
        $referrals = app(Referrals::class);
        $this->assertSame(0, $referrals->discount($friend, 999, null));
        $this->assertSame(200, $referrals->discount($friend, 1000, null));
        $friend->update(['email_verified_at' => null]);
        $this->assertSame(0, $referrals->discount($friend, 1000, null));
        $friend->update(['email_verified_at' => now()]);
        Order::factory()->for($friend)->create(['status' => OrderStatus::Cancelled]);
        $this->assertSame(0, $referrals->discount($friend, 1000, null));
    }

    public function test_matching_device_phone_or_address_prevents_reward(): void
    {
        $referrals = app(Referrals::class);
        $sharer = Customer::factory()->create(['phone' => '012345678'])->fresh();
        $profile = $referrals->profile($sharer, 'same-device-123456');
        $friend = Customer::factory()->create()->fresh();
        $referrals->attribute($friend, $profile->code, 'same-device-123456');
        $this->assertDatabaseCount('referrals', 0);
        $friend2 = Customer::factory()->create(['phone' => '+85512345678'])->fresh();
        $referrals->attribute($friend2, $profile->code, 'other-device-123456');
        $this->assertDatabaseCount('referrals', 0);
        [, $friend3] = $this->pair($sharer);
        $address = CustomerAddress::factory()->for($sharer)->create(['address_line1' => '12 River Road', 'address_line2' => null, 'city' => 'Phnom Penh', 'state' => null]);
        $candidate = new CustomerAddress($address->only(['address_line1', 'address_line2', 'city', 'state', 'country']));
        $candidate->address_line1 = '12 river road';
        $this->assertSame(0, $referrals->discount($friend3, 2000, $candidate));
    }

    public function test_delivered_reward_waits_seven_days_and_is_journalled_once(): void
    {
        [$sharer, $friend, $id] = $this->pair();
        $order = $this->qualify($friend);
        $order->statusHistory()->where('status', 'delivered')->update(['created_at' => now()->subDays(6)]);
        app(Referrals::class)->reward($id);
        $this->assertSame(0, app(CreditWallet::class)->balance($sharer));
        $this->travel(2)->days();
        app(Referrals::class)->reward($id);
        app(Referrals::class)->reward($id);
        $this->assertSame(200, app(CreditWallet::class)->balance($sharer));
        $this->assertDatabaseCount('credit_ledger_entries', 1);
    }

    public function test_monthly_cap_is_ten_rewards_including_later_voided_rewards(): void
    {
        [$sharer, $friend, $id] = $this->pair();
        $this->qualify($friend);
        foreach (range(1, 10) as $i) {
            $other = Customer::factory()->create();
            DB::table('referrals')->insert(['sharer_customer_id' => $sharer->id, 'friend_customer_id' => $other->id, 'status' => 'void', 'rewarded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        app(Referrals::class)->reward($id);
        $this->assertDatabaseHas('referrals', ['id' => $id, 'status' => 'void']);
        $this->assertSame(0, app(CreditWallet::class)->balance($sharer));
    }

    public function test_return_prevents_reward_and_void_recovers_only_available_credit(): void
    {
        [$sharer, $friend, $id] = $this->pair();
        $order = $this->qualify($friend);
        app(Referrals::class)->reward($id);
        app(CreditWallet::class)->move($sharer, -150, 'spent-test');
        app(Referrals::class)->voidForOrder($order);
        app(Referrals::class)->voidForOrder($order);
        $this->assertSame(0, app(CreditWallet::class)->balance($sharer));
        $this->assertDatabaseHas('referrals', ['id' => $id, 'status' => 'void', 'unrecovered_cents' => 150]);
        [, $other, $otherId] = $this->pair();
        $otherOrder = $this->qualify($other);
        OrderReturn::create(['order_id' => $otherOrder->id, 'customer_id' => $other->id, 'reason' => 'Damaged', 'status' => 'requested', 'requested_at' => now()]);
        app(Referrals::class)->reward($otherId);
        $this->assertDatabaseHas('referrals', ['id' => $otherId, 'status' => 'void']);
    }

    public function test_flags_off_disable_attribution_discount_and_rewards(): void
    {
        [$sharer, $friend, $id] = $this->pair();
        $this->qualify($friend);
        config(['shop.referrals_enabled' => false]);
        app(Referrals::class)->reward($id);
        $this->assertSame(0, app(CreditWallet::class)->balance($sharer));
        $this->assertSame(0, app(Referrals::class)->discount($friend, 2000, null));
        $this->getJson('/api/v1/me/referrals')->assertNotFound();
        $this->getJson('/api/v1/staff/referrals')->assertNotFound();
    }

    public function test_unfinished_referred_signups_can_still_be_pruned(): void
    {
        [, $friend] = $this->pair();
        $friend->update(['email_verified_at' => null, 'phone_verified_at' => null, 'created_at' => now()->subDays(4)]);
        app(UnverifiedCustomers::class)->prune();
        $this->assertDatabaseMissing('customers', ['id' => $friend->id]);
        $this->assertDatabaseMissing('referrals', ['friend_customer_id' => $friend->id]);
    }

    public function test_checkout_automatically_saves_and_allocates_the_friend_discount(): void
    {
        [, $friend, $id] = $this->pair();
        $address = CustomerAddress::factory()->for($friend)->create();
        $this->withToken($friend->createToken('t', ['customer'])->plainTextToken);
        $variant = $this->physical('15.00');
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => 1])->assertCreated();
        $this->postJson('/api/v1/checkout/preview', ['address_id' => $address->id])->assertOk()->assertJsonPath('data.referral_discount.usd', '2.00');
        $this->withHeader('Idempotency-Key', 'referral-checkout-1')->postJson('/api/v1/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])->assertCreated()->assertJsonPath('data.discount_usd', '2.00')->assertJsonPath('data.items.0.discount_usd', '2.00');
        $this->assertDatabaseHas('referrals', ['id' => $id, 'status' => 'qualified', 'discount_cents' => 200]);
    }
}

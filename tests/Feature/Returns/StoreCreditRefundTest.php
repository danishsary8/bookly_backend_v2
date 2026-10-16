<?php

namespace Tests\Feature\Returns;

use App\Enums\CouponType;
use App\Enums\ReturnStatus;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\OrderReturn;
use App\Notifications\ReturnUpdatedNotification;
use App\Services\Cart\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class StoreCreditRefundTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    private Customer $customer;

    private string $customerToken;

    private string $staffToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::factory()->create(['email' => 'customer1@example.com']);
        $this->customerToken = $this->customer->createToken('test', ['customer'])->plainTextToken;
        $this->staffToken = $this->staffToken();
    }

    private function approvedReturn(Customer $customer, int $qty = 1, string $price = '15.00'): OrderReturn
    {
        $variant = $this->physical($price);
        $order = $this->deliveredOrder($customer, [[$variant, $qty]]);

        $return = OrderReturn::create([
            'order_id' => $order->id,
            'customer_id' => $customer->id,
            'reason' => 'Defective cover',
            'status' => ReturnStatus::Approved,
            'requested_at' => now()->subDay(),
        ]);

        $return->items()->create([
            'order_item_id' => $order->items->first()->id,
            'quantity' => $qty,
            'reason' => 'Defective cover',
        ]);

        return $return;
    }

    private function refund(OrderReturn $return, array $body)
    {
        return $this->withToken($this->staffToken)->postJson("/api/v1/staff/returns/{$return->id}/refund", $body);
    }

    private function couponError(Customer $customer, string $code): ?string
    {
        try {
            app(CouponService::class)->evaluate($customer, $code, 5000);
        } catch (ValidationException $e) {
            return $e->errors()['code'][0];
        }

        return null;
    }

    public function test_guest_and_customer_cannot_call_staff_refund(): void
    {
        $return = $this->approvedReturn($this->customer);

        $this->postJson("/api/v1/staff/returns/{$return->id}/refund", ['method' => 'cash'])->assertUnauthorized();
        $this->withToken($this->customerToken)->postJson("/api/v1/staff/returns/{$return->id}/refund", ['method' => 'cash'])->assertForbidden();
    }

    public function test_method_is_required_and_must_be_one_of_the_three(): void
    {
        $return = $this->approvedReturn($this->customer);

        $this->refund($return, [])->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->refund($return, ['method' => 'manual_offline'])->assertUnprocessable()->assertJsonValidationErrors('method');
        $this->assertSame(ReturnStatus::Approved, $return->fresh()->status);
    }

    public function test_cash_refund_records_method_who_and_when(): void
    {
        Carbon::setTestNow('2026-10-16 03:00:00');
        $return = $this->approvedReturn($this->customer);

        $this->refund($return, ['method' => 'cash'])->assertOk()
            ->assertJsonPath('data.refund_method', 'cash')
            ->assertJsonPath('data.refund_reference', null)
            ->assertJsonPath('data.refund_amount_usd', '15.00')
            ->assertJsonPath('data.refunded_by.name', OrderReturn::find($return->id)->refundedBy->name)
            ->assertJsonPath('data.store_credit', null);

        $fresh = $return->fresh();
        $this->assertSame('cash', $fresh->refund_method);
        $this->assertNotNull($fresh->refunded_by_staff_id);
        $this->assertTrue($fresh->refunded_at->equalTo(now()));
        $this->assertNull($fresh->store_credit_coupon_id);
        $this->assertSame(0, Coupon::count());
    }

    public function test_reference_is_optional_and_only_allowed_for_bank_transfer(): void
    {
        $cash = $this->approvedReturn($this->customer);
        $this->refund($cash, ['method' => 'cash', 'reference' => 'ABA-1'])->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->refund($cash, ['method' => 'store_credit', 'reference' => 'ABA-1'])->assertUnprocessable()->assertJsonValidationErrors('reference');

        $bank = $this->approvedReturn($this->customer);
        $this->refund($bank, ['method' => 'bank_transfer', 'reference' => str_repeat('x', 101)])->assertUnprocessable();
        $this->refund($bank, ['method' => 'bank_transfer', 'reference' => 'ABA-TX-12345678'])->assertOk()
            ->assertJsonPath('data.refund_method', 'bank_transfer')
            ->assertJsonPath('data.refund_reference', 'ABA-TX-12345678');

        $noRef = $this->approvedReturn($this->customer);
        $this->refund($noRef, ['method' => 'bank_transfer'])->assertOk()->assertJsonPath('data.refund_reference', null);
    }

    public function test_store_credit_makes_a_one_use_coupon_for_this_customer_valid_12_months_shop_time(): void
    {
        Carbon::setTestNow('2026-10-16 20:00:00'); // 03:00 on the 17th in Phnom Penh
        $return = $this->approvedReturn($this->customer, 2, '10.00');

        $this->refund($return, ['method' => 'store_credit'])->assertOk()
            ->assertJsonPath('data.refund_method', 'store_credit')
            ->assertJsonPath('data.refund_amount_usd', '20.00');

        $coupon = Coupon::sole();
        $this->assertMatchesRegularExpression('/^CREDIT-[A-HJ-NP-Z2-9]{8}$/', $coupon->code);
        $this->assertSame(CouponType::Fixed, $coupon->type);
        $this->assertSame('20.00', $coupon->value);
        $this->assertSame(1, $coupon->max_uses);
        $this->assertSame($this->customer->id, $coupon->customer_id);
        $this->assertSame('2027-10-17 23:59:59', $coupon->expires_at->timezone('Asia/Phnom_Penh')->format('Y-m-d H:i:s'));
        $this->assertSame($coupon->id, $return->fresh()->store_credit_coupon_id);
    }

    public function test_someone_elses_store_credit_answers_like_an_unknown_code(): void
    {
        $this->refund($this->approvedReturn($this->customer), ['method' => 'store_credit'])->assertOk();
        $code = Coupon::sole()->code;
        $other = Customer::factory()->create();

        $this->assertSame(CouponService::NOT_FOUND, $this->couponError($other, $code));
        $this->assertSame(CouponService::NOT_FOUND, $this->couponError($other, 'NO-SUCH-CODE'));
        $this->assertNull($this->couponError($this->customer, $code));
    }

    public function test_store_credit_can_be_used_once(): void
    {
        $this->refund($this->approvedReturn($this->customer), ['method' => 'store_credit'])->assertOk();
        $coupon = Coupon::sole();
        $coupon->update(['used_count' => 1]);

        $this->assertSame('This coupon has reached its usage limit.', $this->couponError($this->customer, $coupon->code));
    }

    public function test_refund_email_says_how_the_money_came_back(): void
    {
        Notification::fake();
        $bank = $this->approvedReturn($this->customer);
        $this->refund($bank, ['method' => 'bank_transfer', 'reference' => 'ABA-77'])->assertOk();
        $credit = $this->approvedReturn($this->customer);
        $this->refund($credit, ['method' => 'store_credit'])->assertOk();

        $mails = [];
        Notification::assertSentTo($this->customer, ReturnUpdatedNotification::class, function ($n) use (&$mails) {
            $mails[] = implode(' ', $n->toMail($this->customer)->introLines);

            return true;
        });
        $text = implode("\n", $mails);
        $this->assertStringContainsString('bank transfer (reference: ABA-77)', $text);
        $this->assertStringContainsString(Coupon::sole()->code, $text);
        $this->assertStringContainsString('15.00 USD', $text);
    }

    public function test_cannot_refund_twice(): void
    {
        $return = $this->approvedReturn($this->customer);
        $this->refund($return, ['method' => 'cash'])->assertOk();
        $this->refund($return, ['method' => 'store_credit'])->assertUnprocessable();
        $this->assertSame(0, Coupon::count());
    }

    public function test_customer_sees_own_store_credits_including_used_and_expired(): void
    {
        $mine = fn (array $extra) => Coupon::create([...[
            'code' => 'CREDIT-'.strtoupper(substr(md5((string) random_int(0, PHP_INT_MAX)), 0, 8)), 'type' => CouponType::Fixed,
            'value' => '5.00', 'max_uses' => 1, 'used_count' => 0, 'is_active' => true, 'customer_id' => $this->customer->id,
            'expires_at' => now()->addYear(),
        ], ...$extra]);
        $mine([]);
        $mine(['used_count' => 1]);
        $mine(['expires_at' => now()->subDay()]);
        Coupon::create(['code' => 'PUBLIC10', 'type' => CouponType::Fixed, 'value' => '1.00', 'is_active' => true]);
        $mine(['customer_id' => Customer::factory()->create()->id]);

        $data = $this->withToken($this->customerToken)->getJson('/api/v1/me/store-credits')->assertOk()->json('data');

        $this->assertCount(3, $data);
        $this->assertEqualsCanonicalizing([false, true, false], array_column($data, 'used'));
        $this->assertEqualsCanonicalizing([false, false, true], array_column($data, 'expired'));
        $this->assertSame('5.00', $data[0]['amount_usd']);
    }

    public function test_old_refunds_without_a_record_still_show(): void
    {
        $return = $this->approvedReturn($this->customer);
        $return->update(['status' => ReturnStatus::Refunded, 'refund_amount' => '15.00', 'resolved_at' => now()]);

        $this->withToken($this->staffToken)->getJson("/api/v1/staff/returns/{$return->id}")->assertOk()
            ->assertJsonPath('data.refund_method', null)
            ->assertJsonPath('data.refunded_by', null);
    }

    public function test_staff_coupon_list_labels_store_credit(): void
    {
        $this->refund($this->approvedReturn($this->customer), ['method' => 'store_credit'])->assertOk();

        $this->withToken($this->staffToken)->getJson('/api/v1/staff/coupons')->assertOk()
            ->assertJsonPath('data.0.store_credit_for.id', $this->customer->id)
            ->assertJsonPath('data.0.store_credit_for.name', $this->customer->name);
    }
}

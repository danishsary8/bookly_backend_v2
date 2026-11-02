<?php

namespace Tests\Feature\Payments;

use App\Models\AdminAuditLog;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StaffUser;
use App\Notifications\OrderPlacedNotification;
use App\Notifications\PaidAfterCancelNotification;
use App\Services\Payments\Khqr\KhqrBuilder;
use App\Services\Payments\KhqrPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/** KHQR payments (design docs/superpowers/specs/2026-11-02-khqr-payments-design.md). */
class KhqrPaymentTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private const TOKEN = 'header.eyJleHAiOjQxMDI0NDQ4MDB9.secret-signature-do-not-leak';

    private Customer $customer;

    private string $customerToken;

    private BookVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        ExchangeRate::factory()->create(); // 4100 riel per dollar
        $this->customer = Customer::factory()->create();
        $this->customerToken = $this->customer->createToken('t', ['customer'])->plainTextToken;
        $this->variant = BookVariant::factory()->create(['stock_quantity' => 5, 'price_usd' => '12.50']);
    }

    private function asCustomer(): static
    {
        return $this->asToken($this->customerToken);
    }

    private function bakongMode(): void
    {
        config(['payments.khqr.mode' => 'bakong', 'payments.bakong.token' => self::TOKEN]);
    }

    /** Places a KHQR order for 2 copies and returns its id. */
    private function placeKhqrOrder(int $quantity = 2): int
    {
        $this->asCustomer()->postJson('/api/v1/cart/items', ['book_variant_id' => $this->variant->id, 'quantity' => $quantity])->assertCreated();
        $address = CustomerAddress::factory()->for($this->customer)->create();

        return $this->asCustomer()->withHeader('Idempotency-Key', 'khqr-'.uniqid())->postJson('/api/v1/checkout', [
            'address_id' => $address->id, 'payment_method' => 'khqr',
        ])->assertCreated()->assertJsonPath('data.payment_method', 'khqr')->assertJsonPath('data.status', 'pending')->json('data.id');
    }

    public function test_delivery_options_list_the_ways_to_pay(): void
    {
        $this->getJson('/api/v1/delivery/options')->assertOk()
            ->assertJsonPath('data.payment_methods.0.code', 'cod')
            ->assertJsonPath('data.payment_methods.1', ['code' => 'khqr', 'mode' => 'test', 'pay_minutes' => 15]);

        config(['payments.khqr.enabled' => false]);
        $this->getJson('/api/v1/delivery/options')->assertJsonCount(1, 'data.payment_methods');
    }

    public function test_checkout_with_khqr_holds_stock_shows_a_valid_qr_and_waits_to_announce(): void
    {
        $id = $this->placeKhqrOrder();
        $order = Order::findOrFail($id);
        $this->assertSame(3, $this->variant->fresh()->stock_quantity); // held like cash on delivery
        Notification::assertNotSentTo($this->customer, OrderPlacedNotification::class); // not before payment

        $res = $this->asCustomer()->getJson("/api/v1/orders/{$id}/payment")->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.mode', 'test')
            ->assertJsonPath('data.total_usd', (string) $order->total_amount)
            ->assertJsonCount(1, 'data.qrs')
            ->assertJsonPath('data.qrs.0.currency', 'USD');
        $qr = $res->json('data.qrs.0.qr');
        $this->assertTrue(KhqrBuilder::hasValidCrc($qr));
        $this->assertStringContainsString('29150011bookly@devb', $qr);
        $this->assertStringContainsString($order->order_number, $qr);
        $this->assertSame(md5($qr), $res->json('data.qrs.0.md5'));
        $this->assertEqualsWithDelta(900, $res->json('data.seconds_left'), 5);

        $this->asCustomer()->getJson("/api/v1/orders/{$id}")->assertJsonPath('data.payment_due_at', fn ($v) => is_string($v));
    }

    public function test_khr_qr_uses_whole_riel_and_is_kept(): void
    {
        $id = $this->placeKhqrOrder();
        $total = Order::findOrFail($id)->total_amount;
        $riel = (string) round((float) $total * 4100);

        $this->asCustomer()->postJson("/api/v1/orders/{$id}/payment/qr", ['currency' => 'KHR'])->assertOk()
            ->assertJsonCount(2, 'data.qrs')
            ->assertJsonPath('data.qrs.0.currency', 'USD')
            ->assertJsonPath('data.qrs.1.currency', 'KHR')
            ->assertJsonPath('data.qrs.1.amount', $riel);
        // Asking again reuses it, even if the rate changes meanwhile.
        ExchangeRate::factory()->create(['rate' => '5000', 'effective_at' => now()]);
        $this->asCustomer()->postJson("/api/v1/orders/{$id}/payment/qr", ['currency' => 'KHR'])->assertJsonPath('data.qrs.1.amount', $riel);
        $this->assertSame(2, DB::table('payments')->where('order_id', $id)->count());
        $this->asCustomer()->postJson("/api/v1/orders/{$id}/payment/qr", ['currency' => 'EUR'])->assertUnprocessable();
    }

    public function test_simulate_pays_once_announces_and_closes_the_other_qr(): void
    {
        $id = $this->placeKhqrOrder();
        $this->asCustomer()->postJson("/api/v1/orders/{$id}/payment/qr", ['currency' => 'KHR'])->assertOk();

        $this->asCustomer()->postJson("/api/v1/orders/{$id}/payment/simulate")->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.paid.currency', 'KHR')
            ->assertJsonPath('data.qrs', []);
        $this->assertSame('paid', Order::findOrFail($id)->status->value);
        $this->assertSame(['failed', 'succeeded'], DB::table('payments')->where('order_id', $id)->orderBy('id')->pluck('status')->all());
        Notification::assertSentTo($this->customer, OrderPlacedNotification::class);
        $this->asCustomer()->getJson("/api/v1/orders/{$id}")->assertJsonPath('data.payment_status', 'succeeded')->assertJsonPath('data.payment_due_at', null);

        // A second simulate (double click) does nothing.
        $this->asCustomer()->postJson("/api/v1/orders/{$id}/payment/simulate")->assertUnprocessable();
        Notification::assertSentToTimes($this->customer, OrderPlacedNotification::class, 1);
        $this->assertSame(1, DB::table('order_status_history')->where('order_id', $id)->where('status', 'paid')->count());
    }

    public function test_time_runs_out_the_order_is_cancelled_and_stock_comes_back(): void
    {
        $id = $this->placeKhqrOrder();
        $this->travel(16)->minutes();

        $this->asCustomer()->getJson("/api/v1/orders/{$id}/payment")->assertOk()->assertJsonPath('data.status', 'expired')->assertJsonPath('data.qrs', []);
        $this->assertSame('cancelled', Order::findOrFail($id)->status->value);
        $this->assertSame(5, $this->variant->fresh()->stock_quantity);
        $this->asCustomer()->postJson("/api/v1/orders/{$id}/payment/simulate")->assertUnprocessable();
    }

    public function test_the_housekeeping_command_cancels_unpaid_orders_only_after_the_time(): void
    {
        $late = $this->placeKhqrOrder(1);
        $this->travel(10)->minutes();
        $fresh = $this->placeKhqrOrder(1);
        $this->travel(6)->minutes();

        $this->artisan('payments:expire-khqr')->assertSuccessful();
        $this->assertSame('cancelled', Order::findOrFail($late)->status->value);
        $this->assertSame('pending', Order::findOrFail($fresh)->status->value);
    }

    public function test_simulate_is_not_found_in_bakong_mode_and_other_customers_cannot_see_the_page(): void
    {
        $id = $this->placeKhqrOrder();
        $this->bakongMode();
        Http::fake(['*' => Http::response(['responseCode' => 1, 'errorCode' => 1, 'data' => null])]);
        $this->asCustomer()->postJson("/api/v1/orders/{$id}/payment/simulate")->assertNotFound();

        $other = Customer::factory()->create()->createToken('t', ['customer'])->plainTextToken;
        $this->asToken($other)->getJson("/api/v1/orders/{$id}/payment")->assertNotFound();
    }

    public function test_bakong_mode_marks_the_order_paid_when_bakong_says_so(): void
    {
        $id = $this->placeKhqrOrder();
        $this->bakongMode();
        $payment = DB::table('payments')->where('order_id', $id)->first();
        Http::fake(['api-bakong.nbc.gov.kh/v1/check_transaction_by_md5' => Http::sequence()
            ->push(['responseCode' => 1, 'errorCode' => 1, 'responseMessage' => 'Transaction could not be found.', 'data' => null])
            ->push(['responseCode' => 0, 'data' => ['hash' => 'abc123', 'amount' => (float) $payment->amount, 'currency' => 'USD', 'fromAccountId' => 'someone@aclb']])]);

        $this->asCustomer()->getJson("/api/v1/orders/{$id}/payment")->assertJsonPath('data.status', 'awaiting_payment');
        Cache::forget("khqr-check:{$id}"); // the 3-second limit
        $this->asCustomer()->getJson("/api/v1/orders/{$id}/payment")->assertJsonPath('data.status', 'paid');

        Http::assertSent(fn ($request) => $request['md5'] === $payment->provider_transaction_id && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));
        $this->assertSame(1, DB::table('payment_webhook_logs')->where('provider', 'bakong')->where('event_id', $payment->provider_transaction_id)->whereNotNull('processed_at')->count());
        Notification::assertSentTo($this->customer, OrderPlacedNotification::class);
    }

    public function test_bakong_asks_at_most_every_3_seconds_per_order(): void
    {
        $id = $this->placeKhqrOrder();
        $this->bakongMode();
        Http::fake(['*' => Http::response(['responseCode' => 1, 'errorCode' => 1, 'data' => null])]);

        foreach (range(1, 4) as $i) {
            $this->asCustomer()->getJson("/api/v1/orders/{$id}/payment")->assertOk();
        }
        Http::assertSentCount(1);
    }

    public function test_a_wrong_amount_or_currency_from_bakong_is_not_counted(): void
    {
        $id = $this->placeKhqrOrder();
        $this->bakongMode();
        Http::fake(['*' => Http::response(['responseCode' => 0, 'data' => ['hash' => 'x', 'amount' => 0.01, 'currency' => 'USD']])]);

        $this->asCustomer()->getJson("/api/v1/orders/{$id}/payment")->assertJsonPath('data.status', 'awaiting_payment');
        $this->assertSame('pending', Order::findOrFail($id)->status->value);
        $this->assertSame(1, DB::table('payment_webhook_logs')->whereNull('processed_at')->count()); // kept for staff
    }

    public function test_bakong_blocked_keeps_the_page_working_and_shows_on_the_health_card(): void
    {
        $id = $this->placeKhqrOrder();
        $this->bakongMode();
        Http::fake(['*' => Http::response('Forbidden', 403)]);

        $this->asCustomer()->getJson("/api/v1/orders/{$id}/payment")->assertOk()->assertJsonPath('data.status', 'awaiting_payment');
        $this->asToken($this->staffToken(admin: true))->getJson('/api/v1/staff/health')->assertOk()
            ->assertJsonPath('checks.payments.mode', 'bakong')
            ->assertJsonPath('checks.payments.status', 'blocked')
            ->assertJsonPath('checks.payments.token_expires_at', '2100-01-01T00:00:00+00:00');
    }

    public function test_a_payment_that_arrives_after_the_cancel_is_kept_and_staff_are_told(): void
    {
        $id = $this->placeKhqrOrder();
        StaffUser::factory()->create();
        $this->asCustomer()->postJson("/api/v1/orders/{$id}/cancel")->assertOk();
        $this->bakongMode();
        $payment = DB::table('payments')->where('order_id', $id)->first();
        Http::fake(['*' => Http::response(['responseCode' => 0, 'data' => ['hash' => 'late', 'amount' => (float) $payment->amount, 'currency' => 'USD']])]);

        // The order is cancelled, so refresh() does not ask Bakong; the late answer comes through the service.
        $service = app(KhqrPaymentService::class);
        (fn () => $this->markPaid(Order::findOrFail($id), Payment::findOrFail($payment->id), ['amount' => (float) $payment->amount, 'currency' => 'USD', 'hash' => 'late'], 'KHQR (Bakong)'))->call($service);

        $this->assertSame('cancelled', Order::findOrFail($id)->status->value);
        $this->assertSame('succeeded', DB::table('payments')->where('id', $payment->id)->value('status'));
        $this->assertTrue(DB::table('order_status_history')->where('order_id', $id)->where('note', 'like', '%refund the customer by hand%')->exists());
        Notification::assertSentTo(StaffUser::all(), PaidAfterCancelNotification::class);
    }

    public function test_admin_marks_paid_with_a_note_staff_cannot(): void
    {
        $id = $this->placeKhqrOrder();
        $this->asToken($this->staffToken())->postJson("/api/v1/staff/orders/{$id}/mark-paid", ['note' => 'Seen in ABA app'])->assertForbidden();
        $admin = $this->staffToken(admin: true);
        $this->asToken($admin)->postJson("/api/v1/staff/orders/{$id}/mark-paid", [])->assertUnprocessable()->assertJsonValidationErrors('note');

        $this->asToken($admin)->postJson("/api/v1/staff/orders/{$id}/mark-paid", ['note' => 'Seen in ABA app'])->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payments.0.how', 'staff')
            ->assertJsonPath('data.payments.0.provider', 'bakong');
        $this->assertTrue(AdminAuditLog::where('action', 'order.status_changed')->where('after_data->status', 'paid')->exists());
        $this->asToken($admin)->postJson("/api/v1/staff/orders/{$id}/mark-paid", ['note' => 'again'])->assertUnprocessable();
        $this->asToken($admin)->getJson('/api/v1/staff/orders?payment_method=khqr')->assertJsonCount(1, 'data');
        $this->asToken($admin)->getJson('/api/v1/staff/orders?payment_method=cod')->assertJsonCount(0, 'data');
    }

    public function test_the_token_never_appears_in_answers_or_logs(): void
    {
        $id = $this->placeKhqrOrder();
        $this->bakongMode();
        Log::spy();
        Http::fake(['*' => Http::response('Unauthorized', 401)]);

        $page = $this->asCustomer()->getJson("/api/v1/orders/{$id}/payment")->assertOk()->getContent();
        $health = $this->asToken($this->staffToken(admin: true))->getJson('/api/v1/staff/health')->assertJsonPath('checks.payments.status', 'token_invalid')->getContent();
        $this->assertStringNotContainsString('secret-signature', $page.$health.json_encode(DB::table('payments')->get()).json_encode(DB::table('payment_webhook_logs')->get()));
        Log::shouldNotHaveReceived('error', [\Mockery::on(fn ($m) => str_contains((string) $m, 'secret-signature'))]);
    }

    public function test_polling_never_uses_up_the_buttons(): void
    {
        $id = $this->placeKhqrOrder();
        foreach (range(1, 25) as $i) {
            $this->asCustomer()->getJson("/api/v1/orders/{$id}/payment")->assertOk(); // a bit over a minute of polling
        }
        $this->asCustomer()->postJson("/api/v1/orders/{$id}/payment/qr", ['currency' => 'KHR'])->assertOk();
        $this->asCustomer()->postJson("/api/v1/orders/{$id}/payment/simulate")->assertOk()->assertJsonPath('data.status', 'paid');
    }

    public function test_cash_on_delivery_is_unchanged(): void
    {
        $this->asCustomer()->postJson('/api/v1/cart/items', ['book_variant_id' => $this->variant->id, 'quantity' => 1])->assertCreated();
        $address = CustomerAddress::factory()->for($this->customer)->create();
        $id = $this->asCustomer()->withHeader('Idempotency-Key', 'cod-key-0001')->postJson('/api/v1/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])
            ->assertCreated()->assertJsonPath('data.payment_due_at', null)->json('data.id');
        Notification::assertSentTo($this->customer, OrderPlacedNotification::class);
        $this->asCustomer()->getJson("/api/v1/orders/{$id}/payment")->assertNotFound();
        $this->asCustomer()->withHeader('Idempotency-Key', 'card-key-0001')->postJson('/api/v1/checkout', ['address_id' => $address->id, 'payment_method' => 'card'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    }
}

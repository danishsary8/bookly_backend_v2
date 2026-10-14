<?php

namespace Tests\Feature\Orders;

use App\Enums\BookFormat;
use App\Enums\ReturnStatus;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\ExchangeRate;
use App\Models\Order;
use App\Models\OrderReturn;
use App\Models\StaffUser;
use App\Services\CurrencyService;
use App\Services\Orders\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class OrderDocumentsTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private BookVariant $paperback;

    private BookVariant $ebook;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $book = Book::factory()->create(['title' => 'Emma']);
        $book->authors()->attach(Author::factory()->create(['name' => 'Jane Austen']));
        $this->paperback = BookVariant::factory()->for($book)->create(['sku' => 'EMMA-PB', 'isbn' => '9780141439587', 'price_usd' => '8.00', 'stock_quantity' => 20]);
        $this->ebook = BookVariant::factory()->for($book)->create(['format' => BookFormat::Ebook, 'sku' => 'EMMA-EB', 'isbn' => null, 'price_usd' => '3.00']);
    }

    /** Places a cash-on-delivery order through the API and returns [order id, customer token]. */
    private function placeOrder(string $email = 'dara@example.com'): array
    {
        $customer = Customer::factory()->create(['email' => $email]);
        $address = CustomerAddress::factory()->for($customer)->create();
        $token = $customer->createToken('t', ['customer'])->plainTextToken;
        $this->asToken($token)->postJson('/api/v1/cart/items', ['book_variant_id' => $this->paperback->id, 'quantity' => 2])->assertCreated();
        $this->asToken($token)->postJson('/api/v1/cart/items', ['book_variant_id' => $this->ebook->id, 'quantity' => 1])->assertCreated();
        $id = $this->withHeader('Idempotency-Key', 'key-'.md5($email))->postJson('/api/v1/checkout', [
            'address_id' => $address->id, 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');

        return [$id, $token];
    }

    private function documents(string $token, array $ids)
    {
        return $this->asToken($token)->getJson('/api/v1/staff/orders/documents?'.http_build_query(['ids' => $ids]));
    }

    public function test_staff_get_print_data_for_several_orders_in_the_order_asked(): void
    {
        [$first] = $this->placeOrder('a@example.com');
        [$second] = $this->placeOrder('b@example.com');
        $staff = $this->staffToken();

        $response = $this->documents($staff, [$second, $first])->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $second)
            ->assertJsonPath('data.1.id', $first)
            ->assertJsonPath('data.0.customer.email', 'b@example.com')
            ->assertJsonPath('data.0.payment_method', 'cod')
            ->assertJsonPath('data.0.items.0.title', 'Emma')
            ->assertJsonPath('data.0.items.0.authors', ['Jane Austen'])
            ->assertJsonPath('data.0.items.0.sku', 'EMMA-PB')
            ->assertJsonPath('data.0.items.0.isbn', '9780141439587')
            ->assertJsonPath('data.0.items.0.format', 'paperback')
            ->assertJsonPath('data.0.items.0.is_digital', false)
            ->assertJsonPath('data.0.items.0.quantity', 2)
            ->assertJsonPath('data.0.items.1.is_digital', true)
            ->assertJsonPath('data.0.refunded_usd', '0.00')
            ->assertJsonPath('data.0.delivered_at', null)
            ->assertJsonPath('data.0.khr', null);

        $total = $response->json('data.0.total_usd');
        $this->assertSame($total, $response->json('data.0.amount_to_collect_usd'), 'cash on delivery: the courier collects the total');
    }

    public function test_khr_uses_the_rate_in_force_when_the_order_was_placed(): void
    {
        ExchangeRate::create(['base_currency' => 'USD', 'target_currency' => 'KHR', 'rate' => '4000', 'effective_at' => now()->subDay()]);
        [$id] = $this->placeOrder();
        $this->travel(2)->hours();
        ExchangeRate::create(['base_currency' => 'USD', 'target_currency' => 'KHR', 'rate' => '4200', 'effective_at' => now()->subMinute()]);

        $total = Order::find($id)->total_amount;
        $this->documents($this->staffToken(), [$id])->assertOk()
            ->assertJsonPath('data.0.khr.rate', '4000.000000')
            ->assertJsonPath('data.0.khr.total', number_format((float) $total * 4000, 0, '.', ''))
            ->assertJsonPath('data.0.khr.amount_to_collect', number_format((float) $total * 4000, 0, '.', ''));

        // The live order screen moves to today's rate; the printed invoice does not.
        $this->app->forgetInstance(CurrencyService::class);
        $this->asToken($this->staffToken())->getJson("/api/v1/staff/orders/{$id}")
            ->assertJsonPath('data.total_khr', number_format((float) $total * 4200, 0, '.', ''));
    }

    public function test_delivered_order_has_nothing_left_to_collect_and_shows_refunds(): void
    {
        [$id] = $this->placeOrder();
        $staff = $this->staffToken();
        foreach (['processing', 'shipped', 'delivered'] as $status) {
            $this->asToken($staff)->postJson("/api/v1/staff/orders/{$id}/status", ['status' => $status])->assertOk();
        }
        $order = Order::find($id);
        foreach ([['refunded', '5.50'], ['refunded', '2.00'], ['rejected', null]] as [$status, $amount]) {
            OrderReturn::create([
                'order_id' => $id, 'customer_id' => $order->customer_id, 'reason' => 'Damaged',
                'status' => ReturnStatus::from($status), 'refund_amount' => $amount, 'requested_at' => now(), 'resolved_at' => now(),
            ]);
        }

        $this->documents($staff, [$id])->assertOk()
            ->assertJsonPath('data.0.status', 'delivered')
            ->assertJsonPath('data.0.payment_status', 'succeeded')
            ->assertJsonPath('data.0.amount_to_collect_usd', '0.00')
            ->assertJsonPath('data.0.refunded_usd', '7.50')
            ->assertJsonPath('data.0.delivered_at', fn ($at) => $at !== null);
    }

    public function test_a_fully_returned_order_has_nothing_to_collect(): void
    {
        [$id] = $this->placeOrder();
        $staff = $this->staffToken();
        foreach (['processing', 'shipped', 'delivered'] as $status) {
            $this->asToken($staff)->postJson("/api/v1/staff/orders/{$id}/status", ['status' => $status])->assertOk();
        }
        // What the returns flow does once every returnable item is refunded.
        $order = Order::find($id);
        app(OrderStatusService::class)->markReturned($order, StaffUser::first());

        $this->documents($staff, [$id])->assertOk()
            ->assertJsonPath('data.0.status', 'returned')
            ->assertJsonPath('data.0.payment_status', 'refunded')
            ->assertJsonPath('data.0.amount_to_collect_usd', '0.00');
    }

    public function test_a_batch_reads_the_exchange_rates_once_and_each_order_gets_its_own_date(): void
    {
        ExchangeRate::create(['base_currency' => 'USD', 'target_currency' => 'KHR', 'rate' => '4000', 'effective_at' => now()->subDay()]);
        [$first] = $this->placeOrder('a@example.com');
        $this->travel(1)->hours();
        ExchangeRate::create(['base_currency' => 'USD', 'target_currency' => 'KHR', 'rate' => '4100', 'effective_at' => now()->subMinute()]);
        [$second] = $this->placeOrder('b@example.com');
        [$third] = $this->placeOrder('c@example.com');
        $staff = $this->staffToken();

        DB::enableQueryLog();
        $this->documents($staff, [$first, $second, $third])->assertOk()
            ->assertJsonPath('data.0.khr.rate', '4000.000000')
            ->assertJsonPath('data.1.khr.rate', '4100.000000')
            ->assertJsonPath('data.2.khr.rate', '4100.000000');
        $rateQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'exchange_rates'));
        $this->assertCount(1, $rateQueries);
    }

    public function test_a_deleted_book_still_prints(): void
    {
        [$id] = $this->placeOrder();
        $this->paperback->book->delete();

        $this->documents($this->staffToken(), [$id])->assertOk()->assertJsonPath('data.0.items.0.title', 'Emma');
    }

    public function test_batch_is_validated_and_unknown_orders_are_left_out(): void
    {
        [$id] = $this->placeOrder();
        $staff = $this->staffToken();

        $this->asToken($staff)->getJson('/api/v1/staff/orders/documents')->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->documents($staff, range(1, 51))->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->documents($staff, [$id, $id])->assertUnprocessable();
        $this->documents($staff, ['abc'])->assertUnprocessable();
        $this->documents($staff, [$id, 999999])->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_only_staff_can_print_any_order(): void
    {
        [$id, $customerToken] = $this->placeOrder();

        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson('/api/v1/staff/orders/documents?ids[]='.$id)->assertUnauthorized();
        $this->documents($customerToken, [$id])->assertForbidden();
    }

    public function test_customers_print_the_invoice_of_their_own_orders_only(): void
    {
        [$mine, $token] = $this->placeOrder('mine@example.com');
        [$theirs] = $this->placeOrder('theirs@example.com');

        $this->asToken($token)->getJson("/api/v1/orders/{$mine}/invoice")->assertOk()
            ->assertJsonPath('data.id', $mine)
            ->assertJsonPath('data.items.0.sku', 'EMMA-PB')
            ->assertJsonPath('data.customer.email', 'mine@example.com');
        $this->asToken($token)->getJson("/api/v1/orders/{$theirs}/invoice")->assertNotFound();
        $this->asToken($this->staffToken())->getJson("/api/v1/orders/{$mine}/invoice")->assertForbidden();
    }

    public function test_a_cancelled_order_has_no_customer_invoice(): void
    {
        [$id, $token] = $this->placeOrder();
        $this->asToken($token)->postJson("/api/v1/orders/{$id}/cancel")->assertOk();

        $this->asToken($token)->getJson("/api/v1/orders/{$id}/invoice")
            ->assertUnprocessable()->assertJsonPath('errors.order.0', 'A cancelled order has no invoice.');
    }
}

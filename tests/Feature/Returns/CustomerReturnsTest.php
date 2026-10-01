<?php

namespace Tests\Feature\Returns;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\OrderReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class CustomerReturnsTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.return_window_days' => 14]);
        $this->customer = Customer::factory()->create();
        $this->withToken($this->customer->createToken('t', ['customer'])->plainTextToken);
    }

    private function requestReturn(int $orderId, array $items, string $reason = 'Damaged cover')
    {
        return $this->postJson("/api/v1/orders/{$orderId}/returns", ['reason' => $reason, 'items' => $items]);
    }

    public function test_returnable_items_list_excludes_digital_and_shows_deadline(): void
    {
        $book = $this->physical();
        $ebook = BookVariant::factory()->format(BookFormat::Ebook)->create();
        $order = $this->deliveredOrder($this->customer, [[$book, 3], [$ebook, 1]]);

        $this->getJson("/api/v1/orders/{$order->id}/returnable-items")->assertOk()
            ->assertJsonPath('data.can_request_return', true)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.returnable_quantity', 3)
            ->assertJsonPath('data.returnable_until', fn ($v) => str_starts_with($v, now()->addDays(14)->toDateString()));
    }

    public function test_partial_return_then_estimated_refund_includes_coupon_share(): void
    {
        $a = $this->physical('20.00');
        $b = $this->physical('10.00');
        // subtotal 50.00 (2 x 20 + 1 x 10), coupon discount 5.00 (10%)
        $order = $this->deliveredOrder($this->customer, [[$a, 2], [$b, 1]], '5.00');
        $itemA = $order->items->firstWhere('book_variant_id', $a->id);

        $this->requestReturn($order->id, [['order_item_id' => $itemA->id, 'quantity' => 1, 'reason' => 'Torn page']])
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.items.0.quantity', 1)
            ->assertJsonPath('data.refund_amount_usd', '18.00') // 20.00 minus 2.00 coupon share
            ->assertJsonPath('data.is_refund_final', false)
            ->assertJsonPath('data.can_withdraw', true);

        $this->getJson("/api/v1/orders/{$order->id}/returnable-items")
            ->assertJsonPath('data.can_request_return', false)
            ->assertJsonPath('data.reason_unavailable', 'This order already has a return request in progress.');
    }

    public function test_cannot_return_more_than_bought_or_digital_items(): void
    {
        $book = $this->physical();
        $ebook = BookVariant::factory()->format(BookFormat::Audiobook)->create();
        $order = $this->deliveredOrder($this->customer, [[$book, 2], [$ebook, 1]]);
        $bookItem = $order->items->firstWhere('book_variant_id', $book->id);
        $ebookItem = $order->items->firstWhere('book_variant_id', $ebook->id);

        $this->requestReturn($order->id, [['order_item_id' => $bookItem->id, 'quantity' => 3]])
            ->assertUnprocessable()->assertJsonPath('errors.items.0', "You can return at most 2 of item {$bookItem->id}.");
        // The same item split across two lines still counts together.
        $this->requestReturn($order->id, [['order_item_id' => $bookItem->id, 'quantity' => 2], ['order_item_id' => $bookItem->id, 'quantity' => 1]])
            ->assertUnprocessable();
        $this->requestReturn($order->id, [['order_item_id' => $ebookItem->id, 'quantity' => 1]])
            ->assertUnprocessable()->assertJsonPath('errors.items.0', 'Only physical books from this order can be returned (ebooks and audiobooks cannot).');

        $this->assertSame(0, OrderReturn::count());
    }

    public function test_window_status_and_ownership_rules(): void
    {
        $book = $this->physical();
        $late = $this->deliveredOrder($this->customer, [[$book, 1]], deliveredAt: now()->subDays(15));
        $pending = $this->deliveredOrder($this->customer, [[$book, 1]]);
        $pending->update(['status' => OrderStatus::Pending]);
        $someoneElses = $this->deliveredOrder(Customer::factory()->create(), [[$book, 1]]);

        $this->requestReturn($late->id, [['order_item_id' => $late->items[0]->id, 'quantity' => 1]])
            ->assertUnprocessable()->assertJsonPath('errors.order.0', 'The return window for this order has closed.');
        $this->requestReturn($pending->id, [['order_item_id' => $pending->items[0]->id, 'quantity' => 1]])
            ->assertUnprocessable()->assertJsonPath('errors.order.0', 'Only delivered orders can be returned.');
        $this->requestReturn($someoneElses->id, [['order_item_id' => $someoneElses->items[0]->id, 'quantity' => 1]])->assertNotFound();
        $this->requestReturn($pending->id, [])->assertUnprocessable()->assertJsonValidationErrors('items');
    }

    public function test_withdraw_only_while_requested_and_list_own_returns(): void
    {
        $order = $this->deliveredOrder($this->customer, [[$this->physical(), 2]]);
        $id = $this->requestReturn($order->id, [['order_item_id' => $order->items[0]->id, 'quantity' => 1]])->json('data.id');

        $this->getJson('/api/v1/returns')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_number', $order->order_number);
        $this->getJson("/api/v1/returns/{$id}")->assertOk();

        $this->deleteJson("/api/v1/returns/{$id}")->assertNoContent();
        $this->assertSame(0, OrderReturn::count());

        // After withdrawing, a new request is possible again; once handled it cannot be withdrawn.
        $second = $this->requestReturn($order->id, [['order_item_id' => $order->items[0]->id, 'quantity' => 2]])->assertCreated()->json('data.id');
        OrderReturn::whereKey($second)->update(['status' => 'approved']);
        $this->deleteJson("/api/v1/returns/{$second}")->assertUnprocessable();
    }

    public function test_customers_cannot_see_other_customers_returns(): void
    {
        $other = Customer::factory()->create();
        $order = $this->deliveredOrder($other, [[$this->physical(), 1]]);
        $return = OrderReturn::create(['order_id' => $order->id, 'customer_id' => $other->id, 'reason' => 'x', 'status' => 'requested', 'requested_at' => now()]);

        $this->getJson("/api/v1/returns/{$return->id}")->assertNotFound();
        $this->deleteJson("/api/v1/returns/{$return->id}")->assertNotFound();
        $this->getJson('/api/v1/returns')->assertJsonCount(0, 'data');
    }
}

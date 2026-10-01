<?php

namespace Tests\Feature\Returns;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AdminAuditLog;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class StaffReturnsTest extends TestCase
{
    use ActsAsStaff, BuildsOrders, RefreshDatabase;

    private Customer $customer;

    private string $customerToken;

    private string $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::factory()->create(['email' => 'dara@example.com']);
        $this->customerToken = $this->customer->createToken('t', ['customer'])->plainTextToken;
        $this->staff = $this->staffToken();
    }

    private function requestReturn(Order $order, array $lines): int
    {
        $items = collect($lines)->map(fn ($l) => ['order_item_id' => $order->items->firstWhere('book_variant_id', $l[0]->id)->id, 'quantity' => $l[1]])->all();

        return $this->asToken($this->customerToken)->postJson("/api/v1/orders/{$order->id}/returns", ['reason' => 'Changed my mind', 'items' => $items])
            ->assertCreated()->json('data.id');
    }

    private function staffPost(int $id, string $action, array $body = [])
    {
        return $this->asToken($this->staff)->postJson("/api/v1/staff/returns/{$id}/{$action}", $body);
    }

    public function test_full_return_flow_restocks_and_marks_order_returned(): void
    {
        $book = $this->physical('12.00', stock: 5);
        $ebook = BookVariant::factory()->format(BookFormat::Ebook)->create(['price_usd' => '4.00']);
        $order = $this->deliveredOrder($this->customer, [[$book, 2], [$ebook, 1]]);
        $id = $this->requestReturn($order, [[$book, 2]]);

        $this->staffPost($id, 'refund')->assertUnprocessable(); // must be approved first
        $this->staffPost($id, 'approve', ['note' => 'Courier pickup booked'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(5, $book->fresh()->stock_quantity, 'approving does not add stock');

        $this->staffPost($id, 'refund', ['note' => 'Paid back by ABA transfer'])->assertOk()
            ->assertJsonPath('data.status', 'refunded')
            ->assertJsonPath('data.refund_amount_usd', '24.00')
            ->assertJsonPath('data.is_refund_final', true)
            ->assertJsonPath('data.staff_note', 'Paid back by ABA transfer')
            ->assertJsonPath('data.handled_by.name', fn ($n) => is_string($n));

        $this->assertSame(7, $book->fresh()->stock_quantity);
        $this->assertDatabaseHas('inventory_movements', ['book_variant_id' => $book->id, 'change_qty' => 2, 'reason' => 'return', 'reference_type' => 'return', 'reference_id' => $id]);

        // Every physical copy came back (the ebook is not returnable), so the order is returned.
        $order->refresh();
        $this->assertSame(OrderStatus::Returned, $order->status);
        $this->assertSame(PaymentStatus::Refunded, $order->payments()->first()->status);
        $this->assertEqualsCanonicalizing(['order_return.approved', 'order_return.refunded', 'order.status_changed'],
            AdminAuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_partial_refunds_add_up_exactly_and_order_stays_delivered_until_all_back(): void
    {
        $book = $this->physical('10.00');
        // 3 copies = 30.00, coupon discount 10.00: a third of 20.00 does not divide evenly.
        $order = $this->deliveredOrder($this->customer, [[$book, 3]], '10.00');
        $refunds = [];

        foreach ([1, 1, 1] as $i => $qty) {
            $id = $this->requestReturn($order->fresh('items'), [[$book, $qty]]);
            $this->staffPost($id, 'approve');
            $refunds[] = $this->staffPost($id, 'refund')->assertOk()->json('data.refund_amount_usd');

            $expected = $i < 2 ? OrderStatus::Delivered : OrderStatus::Returned;
            $this->assertSame($expected, $order->fresh()->status);
        }

        $this->assertSame(['6.67', '6.67', '6.66'], $refunds);
        $this->assertSame(2000, array_sum(array_map(fn ($r) => (int) round($r * 100), $refunds)));
    }

    public function test_reject_needs_a_note_and_frees_the_quantity_again(): void
    {
        $book = $this->physical();
        $order = $this->deliveredOrder($this->customer, [[$book, 1]]);
        $id = $this->requestReturn($order, [[$book, 1]]);

        $this->staffPost($id, 'reject')->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->staffPost($id, 'reject', ['note' => 'Book shows heavy use'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->staffPost($id, 'approve')->assertUnprocessable();

        $this->asToken($this->customerToken)->getJson("/api/v1/orders/{$order->id}/returnable-items")
            ->assertJsonPath('data.items.0.returnable_quantity', 1);
    }

    public function test_list_filters_and_access(): void
    {
        $order = $this->deliveredOrder($this->customer, [[$this->physical(), 1]]);
        $id = $this->requestReturn($order, [[$order->items[0]->variant, 1]]);

        $this->asToken($this->staff)->getJson('/api/v1/staff/returns?status=requested')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer.email', 'dara@example.com');
        $this->asToken($this->staff)->getJson('/api/v1/staff/returns?q='.$order->order_number)->assertJsonCount(1, 'data');
        $this->asToken($this->staff)->getJson('/api/v1/staff/returns?status=refunded')->assertJsonCount(0, 'data');
        $this->asToken($this->staff)->getJson("/api/v1/staff/returns/{$id}")->assertOk();

        $this->asToken($this->customerToken)->postJson("/api/v1/staff/returns/{$id}/approve")->assertForbidden();
        $this->assertSame('requested', OrderReturn::find($id)->status->value);
    }
}

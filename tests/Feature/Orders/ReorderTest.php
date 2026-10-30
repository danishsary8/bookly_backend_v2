<?php

namespace Tests\Feature\Orders;

use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReorderTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->customer = Customer::factory()->create();
        $this->withToken($this->customer->createToken('t', ['customer'])->plainTextToken);
    }

    private function placeOrder(array $variants): int
    {
        foreach ($variants as [$variant, $qty]) {
            $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => $qty])->assertCreated();
        }
        $address = CustomerAddress::factory()->for($this->customer)->create();

        return $this->withHeader('Idempotency-Key', 'reorder-key-0001')->postJson('/api/v1/checkout', [
            'address_id' => $address->id, 'payment_method' => 'cod',
        ])->assertCreated()->json('data.id');
    }

    public function test_reorder_puts_the_order_items_back_in_the_cart(): void
    {
        $a = BookVariant::factory()->create(['stock_quantity' => 10]);
        $b = BookVariant::factory()->create(['stock_quantity' => 10]);
        $id = $this->placeOrder([[$a, 2], [$b, 1]]);

        $this->postJson("/api/v1/orders/{$id}/reorder")->assertOk()
            ->assertJsonCount(2, 'data.cart.items')
            ->assertJsonPath('data.cart.item_count', 3)
            ->assertJsonCount(2, 'data.added')
            ->assertJsonPath('data.skipped', []);
    }

    public function test_items_that_cannot_be_bought_now_are_skipped_with_a_reason(): void
    {
        $ok = BookVariant::factory()->create(['stock_quantity' => 10]);
        $sold = BookVariant::factory()->create(['stock_quantity' => 10]);
        $gone = BookVariant::factory()->create(['stock_quantity' => 10]);
        $id = $this->placeOrder([[$ok, 1], [$sold, 1], [$gone, 1]]);
        $sold->update(['stock_quantity' => 0]);
        $gone->update(['is_active' => false]);

        $res = $this->postJson("/api/v1/orders/{$id}/reorder")->assertOk()
            ->assertJsonCount(1, 'data.cart.items')
            ->assertJsonPath('data.added.0.book_variant_id', $ok->id)
            ->assertJsonCount(2, 'data.skipped');

        $reasons = collect($res->json('data.skipped'))->pluck('reason', 'book_variant_id');
        $this->assertSame(__('messages.out_of_stock'), $reasons[$sold->id]);
        $this->assertSame(__('messages.item_unavailable'), $reasons[$gone->id]);
    }

    public function test_cannot_reorder_someone_elses_order(): void
    {
        $this->postJson('/api/v1/orders/'.Order::factory()->create()->id.'/reorder')->assertNotFound();
    }
}

<?php

namespace Tests\Feature\Shopping;

use App\Enums\BookFormat;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customer = Customer::factory()->create();
        $this->withToken($this->customer->createToken('t', ['customer'])->plainTextToken);
    }

    private function add(BookVariant $variant, int $quantity = 1)
    {
        return $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => $quantity]);
    }

    public function test_empty_cart(): void
    {
        $this->getJson('/api/v1/cart')->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.subtotal_usd', '0.00')
            ->assertJsonPath('data.can_checkout', false);
    }

    public function test_adding_the_same_format_twice_increases_quantity(): void
    {
        ExchangeRate::factory()->create(['rate' => '4100']);
        $variant = BookVariant::factory()->create(['price_usd' => '12.50', 'stock_quantity' => 20]);

        $this->add($variant, 2)->assertCreated();
        $this->add($variant, 1)->assertCreated()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.items.0.line_total_usd', '37.50')
            ->assertJsonPath('data.items.0.line_total_khr', '153750')
            ->assertJsonPath('data.subtotal_usd', '37.50')
            ->assertJsonPath('data.item_count', 3)
            ->assertJsonPath('data.can_checkout', true);
    }

    public function test_stock_and_max_per_line_are_enforced_when_adding(): void
    {
        $few = BookVariant::factory()->create(['stock_quantity' => 3]);
        $many = BookVariant::factory()->create(['stock_quantity' => 100]);
        $none = BookVariant::factory()->create(['stock_quantity' => 0]);

        $this->add($few, 4)->assertUnprocessable()->assertJsonPath('errors.quantity.0', 'Only 3 left in stock.');
        $this->add($few, 3)->assertCreated();
        $this->add($few, 1)->assertUnprocessable();
        $this->add($many, 11)->assertUnprocessable();
        $this->add($many, 10)->assertCreated();
        $this->add($many, 1)->assertUnprocessable()->assertJsonPath('errors.quantity.0', 'You can buy up to 10 copies of the same item per order.');
        $this->add($none)->assertUnprocessable()->assertJsonPath('errors.quantity.0', 'This item is out of stock.');
    }

    public function test_ebooks_and_audiobooks_are_quantity_one_and_need_no_stock(): void
    {
        $ebook = BookVariant::factory()->format(BookFormat::Ebook)->create(['stock_quantity' => 0]);

        $this->add($ebook, 2)->assertUnprocessable();
        $this->add($ebook)->assertCreated()->assertJsonPath('data.can_checkout', true);
        $this->add($ebook)->assertUnprocessable()->assertJsonPath('errors.quantity.0', 'This ebook/audiobook is already in your cart.');
    }

    public function test_inactive_or_deleted_books_cannot_be_added(): void
    {
        $inactive = BookVariant::factory()->create(['is_active' => false]);
        $deleted = BookVariant::factory()->create();
        $deleted->book->delete();

        $this->add($inactive)->assertUnprocessable()->assertJsonValidationErrors('book_variant_id');
        $this->add($deleted)->assertUnprocessable()->assertJsonValidationErrors('book_variant_id');
    }

    public function test_changes_after_adding_show_up_as_issues_not_silent_removals(): void
    {
        $priced = BookVariant::factory()->create(['price_usd' => '10.00', 'stock_quantity' => 10]);
        $shrinking = BookVariant::factory()->create(['stock_quantity' => 10]);
        $sellingOut = BookVariant::factory()->create(['stock_quantity' => 10]);
        $retired = BookVariant::factory()->create(['stock_quantity' => 10]);
        $this->add($priced);
        $this->add($shrinking, 5);
        $this->add($sellingOut);
        $this->add($retired);

        $priced->update(['price_usd' => '12.00']);
        $shrinking->update(['stock_quantity' => 2]);
        $sellingOut->update(['stock_quantity' => 0]);
        $retired->update(['is_active' => false]);

        $items = collect($this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.can_checkout', false)->json('data.items'))
            ->keyBy('book_variant_id');

        $this->assertSame('price_changed', $items[$priced->id]['issues'][0]['code']);
        $this->assertSame('10.00', $items[$priced->id]['issues'][0]['previous_price_usd']);
        $this->assertSame('12.00', $items[$priced->id]['unit_price_usd']);
        $this->assertSame('insufficient_stock', $items[$shrinking->id]['issues'][0]['code']);
        $this->assertSame(2, $items[$shrinking->id]['issues'][0]['available_quantity']);
        $this->assertSame('out_of_stock', $items[$sellingOut->id]['issues'][0]['code']);
        $this->assertSame('unavailable', $items[$retired->id]['issues'][0]['code']);
        $this->assertCount(4, $items);
    }

    public function test_only_price_change_does_not_block_checkout_and_subtotal_skips_blocked_lines(): void
    {
        $ok = BookVariant::factory()->create(['price_usd' => '10.00']);
        $this->add($ok);
        $ok->update(['price_usd' => '11.00']);

        $this->getJson('/api/v1/cart')->assertJsonPath('data.can_checkout', true)->assertJsonPath('data.subtotal_usd', '11.00');

        $gone = BookVariant::factory()->create(['price_usd' => '50.00']);
        $this->add($gone);
        $gone->update(['is_active' => false]);

        $this->getJson('/api/v1/cart')->assertJsonPath('data.can_checkout', false)->assertJsonPath('data.subtotal_usd', '11.00');
    }

    public function test_update_remove_and_clear(): void
    {
        $variant = BookVariant::factory()->create(['stock_quantity' => 5]);
        $other = BookVariant::factory()->create();
        $itemId = $this->add($variant)->json('data.items.0.id');
        $this->add($other);

        $this->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 4])->assertOk()->assertJsonPath('data.items.0.quantity', 4);
        $this->patchJson("/api/v1/cart/items/{$itemId}", ['quantity' => 6])->assertUnprocessable();
        $this->deleteJson("/api/v1/cart/items/{$itemId}")->assertOk()->assertJsonCount(1, 'data.items');
        $this->deleteJson('/api/v1/cart')->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_cart_lines_are_private(): void
    {
        $other = Customer::factory()->create();
        $foreignItem = \App\Models\Cart::create(['customer_id' => $other->id])
            ->items()->create(['book_variant_id' => BookVariant::factory()->create()->id, 'quantity' => 1]);

        $this->patchJson("/api/v1/cart/items/{$foreignItem->id}", ['quantity' => 2])->assertNotFound();
        $this->deleteJson("/api/v1/cart/items/{$foreignItem->id}")->assertNotFound();
    }
}

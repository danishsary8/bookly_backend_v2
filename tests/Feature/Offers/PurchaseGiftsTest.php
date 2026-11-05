<?php

namespace Tests\Feature\Offers;

use App\Models\BookVariant;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Gift;
use App\Models\Offer;
use App\Models\Order;
use App\Services\Orders\OrderStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class PurchaseGiftsTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private function offer(array $attributes = []): Offer
    {
        $gift = Gift::create(['name_en' => 'Highlighter pen', 'name_km' => 'ប៊ិចរំលេច', 'stock' => 1]);

        return Offer::factory()->create(['type' => 'gift', 'gift_id' => $gift->id, 'percent_off' => '0.00', 'min_quantity' => 1, ...$attributes]);
    }

    private function cart(BookVariant $variant): CustomerAddress
    {
        $customer = Customer::factory()->create();
        $this->asToken($customer->createToken('test', ['customer'])->plainTextToken);
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => 1])->assertCreated();

        return CustomerAddress::factory()->create(['customer_id' => $customer->id]);
    }

    public function test_last_gift_is_reserved_once_snapshot_survives_edit_and_cancel_restores(): void
    {
        $offer = $this->offer();
        $variant = BookVariant::factory()->create(['stock_quantity' => 10]);
        $address = $this->cart($variant);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.free_gift.name_en', 'Highlighter pen');
        $payload = ['address_id' => $address->id, 'payment_method' => 'cod'];
        $orderId = $this->withHeader('Idempotency-Key', 'first-gift-order')->postJson('/api/v1/checkout', $payload)
            ->assertCreated()->assertJsonPath('data.free_gift.quantity', 1)->json('data.id');
        $this->assertSame(0, Gift::find($offer->gift_id)->stock);
        $this->postJson('/api/v1/checkout', $payload)->assertOk();
        $this->assertSame(0, Gift::find($offer->gift_id)->stock);
        Gift::find($offer->gift_id)->update(['name_en' => 'Changed']);
        $second = $this->cart($variant);
        $this->withHeader('Idempotency-Key', 'second-gift-order')->postJson('/api/v1/checkout', ['address_id' => $second->id, 'payment_method' => 'cod'])
            ->assertCreated()->assertJsonPath('data.free_gift', null);
        $this->assertSame('Highlighter pen', Order::find($orderId)->free_gift['name_en']);
        app(OrderStatusService::class)->cancelByCustomer(Order::find($orderId));
        $this->assertSame(1, Gift::find($offer->gift_id)->stock);
        $this->assertSame(0, $offer->fresh()->used_count);
    }

    public function test_ended_and_empty_gifts_do_not_apply(): void
    {
        $offer = $this->offer(['ends_at' => now()->subMinute()]);
        $this->cart(BookVariant::factory()->create());
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.free_gift', null);
        $offer->update(['ends_at' => null]);
        Gift::find($offer->gift_id)->update(['stock' => 0]);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.free_gift', null);
    }

    public function test_matching_subtotal_in_mixed_cart_and_percentage_offer_can_combine(): void
    {
        $variant = BookVariant::factory()->create(['price_usd' => '10.00']);
        $offer = $this->offer(['applies_to' => 'selected', 'min_subtotal' => '10.00']);
        $offer->targets()->create(['book_id' => $variant->book_id]);
        Offer::factory()->create(['min_quantity' => 2, 'percent_off' => '10.00']);
        $address = $this->cart($variant);
        $digital = BookVariant::factory()->create(['format' => 'ebook']);
        $this->postJson('/api/v1/cart/items', ['book_variant_id' => $digital->id, 'quantity' => 1])->assertCreated();
        $this->postJson('/api/v1/checkout/preview')->assertOk()->assertJsonPath('data.free_gift.quantity', 1)->assertJsonPath('data.applied', 'offer');
        $this->withHeader('Idempotency-Key', 'mixed-gift-order')->postJson('/api/v1/checkout', ['address_id' => $address->id, 'payment_method' => 'cod'])
            ->assertCreated()->assertJsonPath('data.free_gift.name_en', 'Highlighter pen');
    }

    public function test_gift_admin_requires_admin_and_validates_stock(): void
    {
        $this->asToken($this->staffToken())->postJson('/api/v1/staff/gifts', [])->assertForbidden();
        $this->asToken($this->staffToken(true))->postJson('/api/v1/staff/gifts', ['name_en' => 'Pen', 'name_km' => 'ប៊ិច', 'stock' => -1])->assertUnprocessable();
        $this->postJson('/api/v1/staff/gifts', ['name_en' => 'Pen', 'name_km' => 'ប៊ិច', 'stock' => 2])->assertCreated();
    }

    public function test_admin_creates_gift_offer_and_a_coupon_can_combine(): void
    {
        $gift = Gift::create(['name_en' => 'Pen', 'name_km' => 'ប៊ិច', 'stock' => 2]);
        $this->asToken($this->staffToken(true))->postJson('/api/v1/staff/offers', [
            'name' => 'Free pen', 'type' => 'gift', 'gift_id' => $gift->id, 'applies_to' => 'all',
        ])->assertCreated()->assertJsonPath('data.min_quantity', 1)->assertJsonPath('data.percent_off', '0.00');
        $coupon = Coupon::factory()->create(['code' => 'GIFT10', 'type' => 'percentage', 'value' => '10.00']);
        $address = $this->cart(BookVariant::factory()->create(['price_usd' => '20.00']));
        $this->withHeader('Idempotency-Key', 'coupon-with-gift')->postJson('/api/v1/checkout', ['address_id' => $address->id, 'payment_method' => 'cod', 'coupon_code' => $coupon->code])
            ->assertCreated()->assertJsonPath('data.discount_usd', '2.00')->assertJsonPath('data.free_gift.name_en', 'Pen');
    }
}

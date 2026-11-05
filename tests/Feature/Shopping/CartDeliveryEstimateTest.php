<?php

namespace Tests\Feature\Shopping;

use App\Models\BookVariant;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartDeliveryEstimateTest extends TestCase
{
    use RefreshDatabase;

    public function test_region_choice_uses_existing_fees_and_default_address_takes_precedence(): void
    {
        config(['shop.shipping_fees.phnom_penh' => '1.50', 'shop.shipping_fees.provinces' => '3.00']);
        $customer = Customer::factory()->create();
        $variant = BookVariant::factory()->create(['price_usd' => '10.00']);
        $this->withToken($customer->createToken('t', ['customer'])->plainTextToken)
            ->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => 1])->assertCreated();
        $this->postJson('/api/v1/checkout/preview', ['delivery_area' => 'phnom_penh'])->assertOk()
            ->assertJsonPath('data.has_delivery_address', false)->assertJsonPath('data.shipping_fee.usd', '1.50')->assertJsonStructure(['data' => ['estimated_delivery' => ['formatted']]]);
        $this->postJson('/api/v1/checkout/preview', ['delivery_area' => 'provinces'])->assertOk()->assertJsonPath('data.shipping_fee.usd', '3.00');
        CustomerAddress::factory()->create(['customer_id' => $customer->id, 'city' => 'Phnom Penh', 'state' => null, 'is_default' => true]);
        $this->postJson('/api/v1/checkout/preview', ['delivery_area' => 'provinces'])->assertOk()
            ->assertJsonPath('data.has_delivery_address', true)->assertJsonPath('data.shipping_fee.usd', '1.50');
        $variant->update(['stock_quantity' => 0, 'allow_backorder' => true]);
        $this->postJson('/api/v1/checkout/preview')->assertOk()->assertJsonPath('data.estimated_delivery', null);
    }
}

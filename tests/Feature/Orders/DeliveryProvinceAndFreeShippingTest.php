<?php

namespace Tests\Feature\Orders;

use App\Models\BookVariant;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Services\Orders\DeliveryArea;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryProvinceAndFreeShippingTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'shop.shipping_fees' => [
                'phnom_penh' => '1.50',
                'provinces' => '3.00',
                'province_rates' => [
                    'kandal' => '2.00',
                    'mondulkiri' => '3.50',
                    'ratanakiri' => '3.50',
                ],
            ],
            'shop.free_shipping_threshold' => '50.00',
        ]);
        $this->customer = Customer::factory()->create();
    }

    private function tokenFor(Customer $customer): string
    {
        return $customer->createToken('t', ['customer'])->plainTextToken;
    }

    public function test_delivery_options_public_endpoint_returns_all_25_provinces(): void
    {
        $response = $this->getJson('/api/v1/delivery/options')->assertOk();

        $response->assertJsonPath('data.free_shipping.is_active', true)
            ->assertJsonPath('data.free_shipping.threshold_usd', '50.00')
            ->assertJsonPath('data.shipping_fees.phnom_penh_usd', '1.50')
            ->assertJsonPath('data.shipping_fees.provinces_usd', '3.00')
            ->assertJsonCount(25, 'data.provinces');

        // Check Phnom Penh entry
        $response->assertJsonFragment([
            'code' => 'phnom_penh',
            'name_en' => 'Phnom Penh',
            'name_km' => 'ភ្នំពេញ',
            'area' => 'phnom_penh',
            'fee_usd' => '1.50',
        ]);

        // Check Kandal override entry
        $response->assertJsonFragment([
            'code' => 'kandal',
            'name_en' => 'Kandal',
            'name_km' => 'កណ្តាល',
            'area' => 'provinces',
            'fee_usd' => '2.00',
        ]);

        // Check Mondulkiri override entry
        $response->assertJsonFragment([
            'code' => 'mondulkiri',
            'name_en' => 'Mondulkiri',
            'name_km' => 'មណ្ឌលគិរី',
            'area' => 'provinces',
            'fee_usd' => '3.50',
        ]);
    }

    public function test_preview_calculates_per_province_shipping_fees_and_estimates(): void
    {
        $token = $this->tokenFor($this->customer);
        $variant = BookVariant::factory()->create(['price_usd' => '15.00', 'stock_quantity' => 10]);
        $this->withToken($token)->postJson('/api/v1/cart/items', [
            'book_variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertCreated();

        // 1. Kandal address -> 2.00 USD
        $kandalAddress = CustomerAddress::factory()->for($this->customer)->create([
            'city' => 'Ta Khmau',
            'state' => 'Kandal',
        ]);

        $preview = $this->withToken($token)->postJson('/api/v1/checkout/preview', [
            'address_id' => $kandalAddress->id,
        ])->assertOk();

        $preview->assertJsonPath('data.delivery_area', 'provinces')
            ->assertJsonPath('data.province_code', 'kandal')
            ->assertJsonPath('data.province_name', 'Kandal')
            ->assertJsonPath('data.shipping_fee.usd', '2.00')
            ->assertJsonPath('data.is_free_shipping', false)
            ->assertJsonPath('data.amount_needed_for_free_shipping.usd', '35.00')
            ->assertJsonPath('data.estimated_delivery.days_min', 1)
            ->assertJsonPath('data.estimated_delivery.days_max', 2);

        // 2. Mondulkiri address -> 3.50 USD, 3-4 days
        $mondulkiriAddress = CustomerAddress::factory()->for($this->customer)->create([
            'city' => 'Sen Monorom',
            'state' => 'Mondulkiri',
        ]);

        $preview2 = $this->withToken($token)->postJson('/api/v1/checkout/preview', [
            'address_id' => $mondulkiriAddress->id,
        ])->assertOk();

        $preview2->assertJsonPath('data.province_code', 'mondulkiri')
            ->assertJsonPath('data.shipping_fee.usd', '3.50')
            ->assertJsonPath('data.estimated_delivery.days_min', 3)
            ->assertJsonPath('data.estimated_delivery.days_max', 4);

        // 3. Khmer script detection: សៀមរាប -> Siem Reap, standard provinces fee 3.00 USD
        $srAddress = CustomerAddress::factory()->for($this->customer)->create([
            'city' => 'សៀមរាប',
            'state' => null,
        ]);

        $preview3 = $this->withToken($token)->postJson('/api/v1/checkout/preview', [
            'address_id' => $srAddress->id,
        ])->assertOk();

        $preview3->assertJsonPath('data.province_code', 'siem_reap')
            ->assertJsonPath('data.province_name', 'Siem Reap')
            ->assertJsonPath('data.shipping_fee.usd', '3.00');
    }

    public function test_free_shipping_threshold_applies_when_subtotal_meets_or_exceeds_threshold(): void
    {
        $token = $this->tokenFor($this->customer);
        $variant = BookVariant::factory()->create(['price_usd' => '30.00', 'stock_quantity' => 10]);
        $address = CustomerAddress::factory()->for($this->customer)->create(['city' => 'Siem Reap']);

        // Subtotal = $30.00 < $50.00 threshold -> Shipping = $3.00
        $this->withToken($token)->postJson('/api/v1/cart/items', [
            'book_variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/v1/checkout/preview', ['address_id' => $address->id])
            ->assertOk()
            ->assertJsonPath('data.subtotal.usd', '30.00')
            ->assertJsonPath('data.shipping_fee.usd', '3.00')
            ->assertJsonPath('data.is_free_shipping', false)
            ->assertJsonPath('data.amount_needed_for_free_shipping.usd', '20.00')
            ->assertJsonPath('data.total.usd', '33.00');

        // Add 1 more: subtotal = $60.00 >= $50.00 threshold -> Shipping = $0.00 (FREE!)
        $this->withToken($token)->postJson('/api/v1/cart/items', [
            'book_variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/v1/checkout/preview', ['address_id' => $address->id])
            ->assertOk()
            ->assertJsonPath('data.subtotal.usd', '60.00')
            ->assertJsonPath('data.shipping_fee.usd', '0.00')
            ->assertJsonPath('data.is_free_shipping', true)
            ->assertJsonPath('data.amount_needed_for_free_shipping.usd', '0.00')
            ->assertJsonPath('data.total.usd', '60.00');

        // Place order with free shipping
        $orderResponse = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'free-shipping-order-1')
            ->postJson('/api/v1/checkout', [
                'address_id' => $address->id,
                'payment_method' => 'cod',
            ])->assertCreated();

        $orderResponse->assertJsonPath('data.subtotal_usd', '60.00')
            ->assertJsonPath('data.shipping_fee_usd', '0.00')
            ->assertJsonPath('data.total_usd', '60.00')
            ->assertJsonPath('data.is_free_shipping', true)
            ->assertJsonPath('data.estimated_delivery.days_min', 2)
            ->assertJsonPath('data.estimated_delivery.days_max', 3);

        $order = Order::find($orderResponse->json('data.id'));
        $this->assertSame('0.00', (string) $order->shipping_fee);
    }

    public function test_coupon_discount_can_drop_subtotal_below_free_shipping_threshold(): void
    {
        $token = $this->tokenFor($this->customer);
        $variant = BookVariant::factory()->create(['price_usd' => '55.00', 'stock_quantity' => 10]);
        $address = CustomerAddress::factory()->for($this->customer)->create(['city' => 'Phnom Penh']);
        Coupon::factory()->create(['code' => 'TENOFF', 'type' => 'fixed', 'value' => '10']);

        $this->withToken($token)->postJson('/api/v1/cart/items', [
            'book_variant_id' => $variant->id,
            'quantity' => 1,
        ])->assertCreated();

        // Without coupon: $55.00 >= $50.00 -> free shipping ($0.00)
        $this->withToken($token)->postJson('/api/v1/checkout/preview', ['address_id' => $address->id])
            ->assertOk()
            ->assertJsonPath('data.is_free_shipping', true)
            ->assertJsonPath('data.shipping_fee.usd', '0.00');

        // With $10 coupon: net merchandise = $55 - $10 = $45 < $50.00 -> shipping fee applies ($1.50)
        $this->withToken($token)->postJson('/api/v1/checkout/preview', [
            'address_id' => $address->id,
            'coupon_code' => 'tenoff',
        ])->assertOk()
            ->assertJsonPath('data.subtotal.usd', '55.00')
            ->assertJsonPath('data.discount.usd', '10.00')
            ->assertJsonPath('data.is_free_shipping', false)
            ->assertJsonPath('data.shipping_fee.usd', '1.50')
            ->assertJsonPath('data.amount_needed_for_free_shipping.usd', '5.00')
            ->assertJsonPath('data.total.usd', '46.50');
    }

    public function test_working_days_calculation_skips_sundays(): void
    {
        // Saturday 17 Oct 2026, 10:00 AM
        $saturday = Carbon::parse('2026-10-17 10:00:00', 'Asia/Phnom_Penh');

        $estimate = DeliveryArea::estimate($saturday, 'Phnom Penh', null);

        // 1 working day from Saturday: Sunday skipped -> Monday 19 Oct
        $this->assertSame('2026-10-19', $estimate['earliest_date']);
        // 2 working days from Saturday: Tuesday 20 Oct
        $this->assertSame('2026-10-20', $estimate['latest_date']);
    }
}

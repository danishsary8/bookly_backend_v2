<?php

namespace Tests\Feature\Catalog;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Services\Catalog\SalesSignals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class ShopSignalsTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    public function test_low_stock_only_for_released_physical_formats_and_configured_threshold(): void
    {
        $variant = $this->physical(stock: 3);
        $url = "/api/v1/books/{$variant->book_id}";
        $this->getJson($url)->assertOk()->assertJsonPath('data.low_stock_remaining', 3)
            ->assertJsonPath('data.variants.0.low_stock_remaining', 3);
        foreach ([['stock_quantity' => 0], ['stock_quantity' => 6], ['release_date' => now()->addDay()], ['format' => BookFormat::Ebook], ['is_active' => false]] as $change) {
            $copy = $variant->replicate()->fill($change);
            $this->assertNull($copy->lowStockBadge());
        }
        config(['shop.low_stock_badge' => 2]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.low_stock_remaining', null);
    }

    public function test_cheaper_ebook_does_not_advertise_physical_scarcity_on_card(): void
    {
        $variant = $this->physical(stock: 2);
        BookVariant::factory()->create(['book_id' => $variant->book_id, 'format' => BookFormat::Ebook, 'price_usd' => '1.00']);
        $this->getJson('/api/v1/books')->assertOk()->assertJsonPath('data.0.low_stock_remaining', null);
    }

    public function test_cart_shows_real_selected_format_stock(): void
    {
        $customer = Customer::factory()->create();
        $variant = $this->physical(stock: 2);
        $this->withToken($customer->createToken('t', ['customer'])->plainTextToken)
            ->postJson('/api/v1/cart/items', ['book_variant_id' => $variant->id, 'quantity' => 1])
            ->assertCreated()->assertJsonPath('data.items.0.low_stock_remaining', 2);
    }

    public function test_sales_count_excludes_cancelled_deleted_and_old_orders_and_is_cached_hourly(): void
    {
        $customer = Customer::factory()->create();
        $variant = $this->physical();
        $this->deliveredOrder($customer, [[$variant, 5]]);
        $this->deliveredOrder($customer, [[$variant, 20]])->update(['status' => OrderStatus::Cancelled]);
        $this->deliveredOrder($customer, [[$variant, 20]])->update(['placed_at' => now()->subDays(91)]);
        $this->deliveredOrder($customer, [[$variant, 20]])->delete();
        $this->getJson('/api/v1/books')->assertOk()->assertJsonPath('data.0.sold_count', 5);
        $this->deliveredOrder($customer, [[$variant, 2]]);
        $this->assertSame(5, app(SalesSignals::class)->count($variant->book_id));
        $this->travel(61)->minutes();
        $this->getJson("/api/v1/books/{$variant->book_id}")->assertOk()->assertJsonPath('data.sold_count', 7);
    }

    public function test_fewer_than_five_copies_is_not_shown(): void
    {
        $variant = $this->physical();
        $this->deliveredOrder(Customer::factory()->create(), [[$variant, 4]]);
        $this->getJson('/api/v1/books')->assertOk()->assertJsonPath('data.0.sold_count', null);
    }
}

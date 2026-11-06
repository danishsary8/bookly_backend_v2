<?php

namespace Tests\Feature\Admin;

use App\Enums\BookFormat;
use App\Enums\OrderStatus;
use App\Models\BookVariant;
use App\Models\Customer;
use App\Services\Admin\PurchaseListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrders;
use Tests\TestCase;

class PurchaseListServiceTest extends TestCase
{
    use BuildsOrders, RefreshDatabase;

    public function test_reserved_demand_is_reported_without_counting_it_twice(): void
    {
        $variant = BookVariant::factory()->create(['stock_quantity' => -2, 'low_stock_threshold' => 5, 'allow_backorder' => true]);
        $waiting = $this->deliveredOrder(Customer::factory()->create(), [[$variant, 4]]);
        $waiting->update(['status' => OrderStatus::Processing, 'is_waiting_stock' => true]);
        $cancelled = $this->deliveredOrder(Customer::factory()->create(), [[$variant, 9]]);
        $cancelled->update(['status' => OrderStatus::Cancelled, 'is_waiting_stock' => true]);
        BookVariant::factory()->format(BookFormat::Ebook)->create(['stock_quantity' => 0]);

        $rows = collect(app(PurchaseListService::class)->items())->keyBy('variant_id');

        $this->assertCount(1, $rows);
        $this->assertSame(4, $rows[$variant->id]['waiting_quantity']);
        $this->assertSame(7, $rows[$variant->id]['quantity_to_buy']);
    }

    public function test_waiting_future_release_is_visible_even_when_reserved_stock_is_above_threshold(): void
    {
        $variant = $this->physical(stock: 12);
        $waiting = $this->deliveredOrder(Customer::factory()->create(), [[$variant, 2]]);
        $waiting->update(['status' => OrderStatus::Pending, 'is_waiting_stock' => true]);

        $row = collect(app(PurchaseListService::class)->items())->firstWhere('variant_id', $variant->id);

        $this->assertSame(2, $row['waiting_quantity']);
        $this->assertSame(0, $row['quantity_to_buy']);
    }
}

<?php

namespace Tests\Feature\Orders;

use App\Models\BookVariant;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\StaffUser;
use App\Notifications\LowStockNotification;
use App\Services\Inventory\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class LowStockAlertTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_deduct_reports_threshold_crossing_only_once(): void
    {
        $variant = BookVariant::factory()->create(['stock_quantity' => 7, 'low_stock_threshold' => 5]);
        $order = Order::factory()->create();
        $inventory = app(InventoryService::class);

        $this->assertFalse($inventory->deductForSale($variant, 1, $order)); // 7 -> 6
        $this->assertTrue($inventory->deductForSale($variant, 2, $order));  // 6 -> 4, crosses
        $this->assertFalse($inventory->deductForSale($variant, 1, $order)); // 4 -> 3, already low

        $this->assertSame(3, $variant->fresh()->stock_quantity);
        $this->assertSame(-4, (int) InventoryMovement::where('reason', 'sale')->where('reference_id', $order->id)->sum('change_qty'));
    }

    public function test_restore_for_cancellation_adds_stock_back_and_logs_it(): void
    {
        $variant = BookVariant::factory()->create(['stock_quantity' => 2]);
        $order = Order::factory()->create();

        app(InventoryService::class)->restoreForCancellation($variant->id, 3, $order);

        $this->assertSame(5, $variant->fresh()->stock_quantity);
        $this->assertDatabaseHas('inventory_movements', ['book_variant_id' => $variant->id, 'change_qty' => 3, 'reason' => 'adjustment', 'reference_type' => 'order', 'reference_id' => $order->id]);
    }

    public function test_staff_read_their_in_app_notifications(): void
    {
        $staff = StaffUser::factory()->withTwoFactor()->create();
        $other = StaffUser::factory()->withTwoFactor()->create();
        $variant = BookVariant::factory()->create(['stock_quantity' => 2]);
        Notification::send([$staff, $other], LowStockNotification::for($variant->load('book')));

        $token = $staff->createToken('t', $staff->tokenAbilities())->plainTextToken;
        $response = $this->asToken($token)->getJson('/api/v1/staff/notifications?unread=1')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'low_stock')
            ->assertJsonPath('data.0.data.stock_quantity', 2)
            ->assertJsonPath('meta.unread_count', 1);

        $this->asToken($token)->postJson('/api/v1/staff/notifications/'.$response->json('data.0.id').'/read')->assertOk();
        $this->asToken($token)->getJson('/api/v1/staff/notifications?unread=1')->assertJsonCount(0, 'data');
        $this->assertSame(1, $other->unreadNotifications()->count(), 'each staff member has their own read state');

        $otherId = $other->notifications()->first()->id;
        $this->asToken($token)->postJson("/api/v1/staff/notifications/{$otherId}/read")->assertNotFound();
        $this->asToken($token)->postJson('/api/v1/staff/notifications/read-all')->assertOk();
    }
}

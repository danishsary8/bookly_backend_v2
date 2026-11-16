<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\BookVariant;
use App\Models\InventoryMovement;
use App\Models\NewsletterCampaign;
use App\Models\Order;
use App\Models\StaffUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

class AuditTimelineTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_combined_activity_searches_both_ledgers_and_keeps_sources_and_actor_truthful(): void
    {
        $token = $this->staffToken(admin: true);
        $staff = StaffUser::factory()->create(['name' => 'Stock Manager']);
        $variant = BookVariant::factory()->create(['sku' => 'READ-100']);
        $variant->book->update(['title' => 'Reading 100%']);
        AdminAuditLog::create(['staff_user_id' => $staff->id, 'action' => 'book_variant.restocked', 'entity_type' => 'book_variant', 'entity_id' => $variant->id, 'after_data' => ['stock_quantity' => 10]]);
        InventoryMovement::create(['book_variant_id' => $variant->id, 'change_qty' => -2, 'reason' => 'sale', 'reference_type' => 'order', 'reference_id' => 999]);
        InventoryMovement::create(['book_variant_id' => $variant->id, 'change_qty' => 5, 'reason' => 'restock', 'created_by_staff_id' => $staff->id, 'supplier' => 'Reader Supply']);

        $this->asToken($token)->getJson('/api/v1/staff/audit-logs')->assertOk()->assertJsonCount(1, 'data');
        $all = $this->asToken($token)->getJson('/api/v1/staff/audit-logs?source=all')->assertOk()->assertJsonCount(3, 'data');
        $this->assertContains('inventory.sale', $all->json('meta.actions'));
        $this->assertContains($staff->id, array_column($all->json('meta.actors'), 'id'));
        $this->asToken($token)->getJson('/api/v1/staff/audit-logs?source=all&action=inventory.sale')->assertJsonCount(1, 'data')->assertJsonPath('data.0.staff', null)->assertJsonPath('data.0.after.quantity_change', -2)->assertJsonPath('data.0.after.reference_id', 999);
        $this->asToken($token)->getJson('/api/v1/staff/audit-logs?source=inventory&q=Reader%20Supply')->assertJsonCount(1, 'data');
        $this->asToken($token)->getJson('/api/v1/staff/audit-logs?source=all&q=Stock%20Manager')->assertJsonCount(2, 'data');
        $this->asToken($token)->getJson('/api/v1/staff/audit-logs?source=all&actor=system')->assertJsonCount(1, 'data');
        $this->asToken($token)->getJson('/api/v1/staff/audit-logs?source=all&q=%25')->assertJsonCount(3, 'data');
        $this->asToken($token)->getJson('/api/v1/staff/audit-logs?source=audit&q=READ-100')->assertJsonCount(1, 'data')->assertJsonPath('data.0.context.sku', 'READ-100');
        $this->asToken($token)->getJson('/api/v1/staff/audit-logs?source=all&per_page=1&page=2')->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.last_page', 3);
        $this->asToken($this->staffToken())->getJson('/api/v1/staff/audit-logs?source=all')->assertForbidden();
    }

    public function test_date_filters_follow_shop_days_and_invalid_filters_are_rejected(): void
    {
        $token = $this->staffToken(admin: true);
        $variant = BookVariant::factory()->create();
        foreach (['2026-11-15 16:59:59', '2026-11-15 17:00:00', '2026-11-16 16:59:59', '2026-11-16 17:00:00'] as $at) {
            $movement = new InventoryMovement(['book_variant_id' => $variant->id, 'change_qty' => 1, 'reason' => 'restock']);
            $movement->created_at = $at;
            $movement->save();
        }
        $this->asToken($token)->getJson('/api/v1/staff/audit-logs?source=inventory&from=2026-11-16&to=2026-11-16')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.timezone', 'Asia/Phnom_Penh');
        $this->asToken($token)->getJson('/api/v1/staff/audit-logs?source=invalid')->assertUnprocessable();
        $this->asToken($token)->getJson('/api/v1/staff/audit-logs?from=2026-11-17&to=2026-11-16')->assertUnprocessable();
    }

    public function test_order_history_includes_sales_without_requiring_a_physical_stock_movement(): void
    {
        $token = $this->staffToken(admin: true);
        $order = Order::factory()->create();
        $order->statusHistory()->create(['status' => 'pending']);
        $order->statusHistory()->create(['status' => 'paid']);
        $this->asToken($token)->getJson('/api/v1/staff/audit-logs?source=orders&action=order.placed')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.entity_id', $order->id)->assertJsonPath('data.0.after.order_number', $order->order_number)->assertJsonPath('data.0.staff', null);
        $this->asToken($token)->getJson('/api/v1/staff/audit-logs?source=all&q='.urlencode($order->order_number))->assertJsonCount(2, 'data');
    }

    public function test_campaign_subject_status_filters_and_pagination_are_applied_on_the_server(): void
    {
        $token = $this->staffToken(admin: true);
        foreach ([['Summer reading', 'draft'], ['SUMMER news', 'sent'], ['Winter reading', 'draft']] as [$subject, $status]) {
            NewsletterCampaign::create(['subject_en' => $subject, 'body_en' => 'Fixture message', 'book_ids' => [], 'status' => $status, 'recipients_total' => 0]);
        }
        $this->asToken($token)->getJson('/api/v1/staff/newsletter/campaigns?q=summer&per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('total', 2)->assertJsonPath('current_page', 2);
        $this->asToken($token)->getJson('/api/v1/staff/newsletter/campaigns?q=summer&status=draft')->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject_en', 'Summer reading');
        $this->asToken($token)->getJson('/api/v1/staff/newsletter/campaigns?status=invalid')->assertUnprocessable();
    }
}

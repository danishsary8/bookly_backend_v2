<?php

namespace App\Services\Admin;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read existing ledgers together without copying or inventing historical events. */
class AuditTimeline
{
    public function query(string $source = 'audit'): Builder
    {
        $audit = DB::table('admin_audit_logs')->selectRaw("id, 'audit' AS source, staff_user_id, action, entity_type, entity_id, before_data, after_data, created_at");
        $inventory = DB::table('inventory_movements as m')
            ->leftJoin('book_variants as v', 'v.id', '=', 'm.book_variant_id')
            ->leftJoin('books as b', 'b.id', '=', 'v.book_id')
            ->selectRaw("m.id, 'inventory' AS source, m.created_by_staff_id AS staff_user_id, 'inventory.' || m.reason AS action, 'book_variant' AS entity_type, m.book_variant_id AS entity_id, NULL::jsonb AS before_data,
                jsonb_build_object('movement_id', m.id, 'quantity_change', m.change_qty, 'reason', m.reason, 'reference_type', m.reference_type, 'reference_id', m.reference_id, 'book_id', v.book_id, 'book_title', b.title, 'sku', v.sku, 'supplier', m.supplier, 'unit_cost_usd', m.unit_cost_usd, 'note', m.note) AS after_data, m.created_at");
        $orders = DB::table('order_status_history as h')->join('orders as o', 'o.id', '=', 'h.order_id')
            ->selectRaw("h.id, 'orders' AS source, h.changed_by_staff_id AS staff_user_id, CASE WHEN h.status = 'pending' THEN 'order.placed' ELSE 'order.status_' || h.status END AS action, 'order' AS entity_type, h.order_id AS entity_id, NULL::jsonb AS before_data,
                jsonb_build_object('order_number', o.order_number, 'status', h.status, 'note', h.note, 'order_total_usd', o.total_amount, 'payment_method', o.payment_method) AS after_data, h.created_at");

        $events = match ($source) {
            'inventory' => $inventory,
            'orders' => $orders,
            'all' => $audit->unionAll($inventory)->unionAll($orders),
            default => $audit,
        };

        return DB::query()->fromSub($events, 'events');
    }
}

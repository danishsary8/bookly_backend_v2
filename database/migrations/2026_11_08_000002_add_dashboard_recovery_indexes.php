<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS marketing_reminder_sent_idx ON marketing_outbox (kind, status, sent_at) WHERE customer_id IS NOT NULL');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS orders_customer_placed_idx ON orders (customer_id, placed_at) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS marketing_reminder_sent_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS orders_customer_placed_idx');
    }
};

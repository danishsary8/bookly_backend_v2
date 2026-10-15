<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('placed_at', 'orders_placed_at_index');
            $table->index(['status', 'placed_at'], 'orders_status_placed_at_index');
        });

        Schema::table('order_status_history', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'order_status_history_status_created_at_index');
        });

        Schema::table('returns', function (Blueprint $table) {
            $table->index(['status', 'resolved_at'], 'returns_status_resolved_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropIndex('returns_status_resolved_at_index');
        });

        Schema::table('order_status_history', function (Blueprint $table) {
            $table->dropIndex('order_status_history_status_created_at_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_placed_at_index');
            $table->dropIndex('orders_placed_at_index');
        });
    }
};

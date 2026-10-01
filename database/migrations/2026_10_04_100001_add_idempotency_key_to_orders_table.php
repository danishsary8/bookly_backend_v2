<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Sent by the client on checkout so a retried or double-clicked request returns the same order.
            $table->string('idempotency_key', 100)->nullable()->after('order_number');
            $table->unique(['customer_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['customer_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};

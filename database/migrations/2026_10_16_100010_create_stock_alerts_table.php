<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('book_variant_id')->constrained('book_variants')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('notified_at')->nullable();

            $table->index(['book_variant_id', 'notified_at']);
        });

        DB::statement('CREATE UNIQUE INDEX stock_alerts_active_unique ON stock_alerts (customer_id, book_variant_id) WHERE notified_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alerts');
    }
};

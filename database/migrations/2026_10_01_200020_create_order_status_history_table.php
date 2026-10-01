<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('status', 20);
            $table->text('note')->nullable();
            $table->foreignId('changed_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete(); // null = system-triggered
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });

        DB::statement("ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_status_check CHECK (status IN ('pending','paid','processing','shipped','delivered','cancelled','returned'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};

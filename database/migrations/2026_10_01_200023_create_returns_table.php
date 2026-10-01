<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->text('reason');
            $table->string('status', 20)->default('requested');
            $table->timestamp('requested_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('customer_id');
        });

        DB::statement("ALTER TABLE returns ADD CONSTRAINT returns_status_check CHECK (status IN ('requested','approved','rejected','refunded'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};

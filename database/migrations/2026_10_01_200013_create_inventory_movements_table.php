<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_variant_id')->constrained('book_variants')->restrictOnDelete();
            $table->integer('change_qty'); // positive = stock in, negative = stock out
            $table->string('reason', 30);
            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['book_variant_id', 'created_at']);
        });

        DB::statement("ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_reason_check CHECK (reason IN ('sale','restock','return','adjustment'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};

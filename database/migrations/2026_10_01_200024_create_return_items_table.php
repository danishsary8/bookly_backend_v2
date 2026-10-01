<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_id')->constrained('returns')->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->restrictOnDelete();
            $table->integer('quantity');
            $table->string('reason')->nullable();

            $table->index('return_id');
            $table->index('order_item_id');
        });

        DB::statement('ALTER TABLE return_items ADD CONSTRAINT return_items_quantity_check CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('return_items');
    }
};

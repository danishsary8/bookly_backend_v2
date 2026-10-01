<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('book_variant_id')->constrained('book_variants')->restrictOnDelete();
            $table->integer('quantity');
            $table->decimal('unit_price', 10, 2); // price frozen at purchase time (intentional snapshot)
            $table->decimal('subtotal', 10, 2);

            $table->index('order_id');
            $table->index('book_variant_id');
        });

        DB::statement('ALTER TABLE order_items ADD CONSTRAINT order_items_quantity_check CHECK (quantity > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};

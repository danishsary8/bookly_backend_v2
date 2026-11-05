<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_variants', function (Blueprint $table) {
            $table->date('release_date')->nullable();
            $table->boolean('allow_backorder')->default(false);
        });

        // Stock can drop below 0 only for pre-orders or back-orders; physical in-stock items cannot oversell.
        DB::statement('ALTER TABLE book_variants DROP CONSTRAINT IF EXISTS book_variants_stock_check');
        DB::statement('ALTER TABLE book_variants ADD CONSTRAINT book_variants_stock_check CHECK (stock_quantity >= 0 OR allow_backorder = true OR release_date IS NOT NULL)');

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('is_preorder')->default(false);
            $table->boolean('is_backorder')->default(false);
            $table->date('expected_date')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_waiting_stock')->default(false);
            $table->date('expected_ship_date')->nullable();
        });

        DB::statement('CREATE INDEX IF NOT EXISTS orders_is_waiting_stock_index ON orders (is_waiting_stock) WHERE is_waiting_stock = true');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_is_waiting_stock_index');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['is_waiting_stock', 'expected_ship_date']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['is_preorder', 'is_backorder', 'expected_date']);
        });

        DB::statement('ALTER TABLE book_variants DROP CONSTRAINT IF EXISTS book_variants_stock_check');
        DB::statement('ALTER TABLE book_variants ADD CONSTRAINT book_variants_stock_check CHECK (stock_quantity >= 0)');

        Schema::table('book_variants', function (Blueprint $table) {
            $table->dropColumn(['release_date', 'allow_backorder']);
        });
    }
};

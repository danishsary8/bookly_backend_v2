<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            // Price when the item was added; only used to warn that the price changed since then.
            $table->decimal('unit_price_at_add', 10, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn('unit_price_at_add');
        });
    }
};

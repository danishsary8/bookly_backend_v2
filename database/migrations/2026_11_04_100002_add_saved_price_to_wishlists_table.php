<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-04: the wishlist shows "Now $16.40, was $20.50" when a book got cheaper after it was saved. The
 * lowest price of the book's formats at the moment it was saved; null for books saved before this was added.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->decimal('saved_price_usd', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropColumn('saved_price_usd');
        });
    }
};

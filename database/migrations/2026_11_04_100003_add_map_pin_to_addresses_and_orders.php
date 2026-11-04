<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner 2026-11-04: customers can pin their delivery spot on a map (optional). The pin is kept on the address and
 * copied onto each order (like the rest of the address), so the courier's "Open in Google Maps" link never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('shipping_latitude', 9, 6)->nullable();
            $table->decimal('shipping_longitude', 9, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customer_addresses', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_latitude', 'shipping_longitude']);
        });
    }
};

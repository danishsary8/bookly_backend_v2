<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set offers (owner 2026-11-12): "buy the whole series in one order, save X%" and, as an option on any offer,
 * free delivery for the order the offer applies to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->boolean('whole_series')->default(false); // targets are series; every volume must be in the cart
            $table->boolean('free_delivery')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $table) => $table->dropColumn(['whole_series', 'free_delivery']));
    }
};

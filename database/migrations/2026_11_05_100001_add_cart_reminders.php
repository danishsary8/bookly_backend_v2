<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Owner 2026-11-05: one friendly email 24 h after a signed-in customer leaves books in the cart (on by default). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('cart_reminders')->default(true);
        });
        Schema::table('carts', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('cart_reminders');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Owner 2026-11-04: email the customer when someone signs in from a device the account has not used before. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_known_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('device', 100);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->unique(['customer_id', 'device']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_known_devices');
    }
};

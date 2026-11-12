<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop settings staff change from the admin pages (owner 2026-11-12): one row per key. A missing row means the
 * default from config/shop.php (App\Services\Settings\ShopSettings). First keys: the KHQR discount and the cash on
 * delivery limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->string('value', 255)->nullable(); // null = switched off
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
    }
};

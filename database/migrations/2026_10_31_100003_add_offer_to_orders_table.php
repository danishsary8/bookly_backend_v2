<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The offer an order used, plus a copy of it at order time, so reports stay right after the offer is edited or
 * deleted. The discount itself stays in orders.discount_amount (one discount per order: offer OR coupon).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('offer_id')->nullable()->after('coupon_id')->constrained('offers')->nullOnDelete();
            $table->string('offer_name', 100)->nullable()->after('offer_id');
            $table->smallInteger('offer_min_quantity')->nullable()->after('offer_name');
            $table->decimal('offer_percent_off', 5, 2)->nullable()->after('offer_min_quantity');

            $table->index('offer_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['offer_id']);
            $table->dropConstrainedForeignId('offer_id');
            $table->dropColumn(['offer_name', 'offer_min_quantity', 'offer_percent_off']);
        });
    }
};

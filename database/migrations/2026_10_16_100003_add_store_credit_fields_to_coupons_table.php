<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store credit: a coupon locked to one customer (made when a return is refunded as store credit). Public coupons
 * keep customer_id empty. Customers are never hard-deleted, so restrict keeps a credit from losing its owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('code')->constrained('customers')->restrictOnDelete();
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};

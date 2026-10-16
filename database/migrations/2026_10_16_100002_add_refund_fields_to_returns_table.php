<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The refund record of a return: how the money went back (cash, bank transfer or store credit), an optional bank
 * reference, who refunded it and when, and the store-credit coupon when one was made. Older refunds keep these empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->string('refund_method', 20)->nullable()->after('refund_amount');
            $table->string('refund_reference', 100)->nullable()->after('refund_method');
            $table->foreignId('refunded_by_staff_id')->nullable()->after('refund_reference')->constrained('staff_users')->nullOnDelete();
            $table->timestamp('refunded_at')->nullable()->after('refunded_by_staff_id');
            $table->foreignId('store_credit_coupon_id')->nullable()->after('refunded_at')->constrained('coupons')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_credit_coupon_id');
            $table->dropConstrainedForeignId('refunded_by_staff_id');
            $table->dropColumn(['refund_method', 'refund_reference', 'refunded_at']);
        });
    }
};

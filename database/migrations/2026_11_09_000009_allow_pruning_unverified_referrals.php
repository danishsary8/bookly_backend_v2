<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_profiles', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropForeign(['friend_customer_id']);
            $table->foreign('friend_customer_id')->references('id')->on('customers')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('referral_profiles', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
        });
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropForeign(['friend_customer_id']);
            $table->foreign('friend_customer_id')->references('id')->on('customers')->restrictOnDelete();
        });
    }
};

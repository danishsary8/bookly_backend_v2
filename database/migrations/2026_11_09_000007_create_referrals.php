<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_profiles', function (Blueprint $table) {
            $table->foreignId('customer_id')->primary()->constrained()->restrictOnDelete();
            $table->char('code', 16)->unique();
            $table->char('device_hash', 64)->nullable()->index();
            $table->timestamps();
        });
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sharer_customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('friend_customer_id')->unique()->constrained('customers')->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->integer('discount_cents')->default(0);
            $table->integer('reward_cents')->default(0);
            $table->integer('unrecovered_cents')->default(0);
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->index(['sharer_customer_id', 'rewarded_at']);
        });
        DB::statement("ALTER TABLE referrals ADD CONSTRAINT referral_status_check CHECK (status IN ('pending','qualified','rewarded','void')), ADD CONSTRAINT referral_not_self CHECK (sharer_customer_id <> friend_customer_id), ADD CONSTRAINT referral_money_check CHECK (discount_cents >= 0 AND reward_cents >= 0 AND unrecovered_cents >= 0)");
        Schema::table('orders', fn (Blueprint $table) => $table->integer('referral_discount_cents')->default(0));
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('referral_discount_cents'));
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('referral_profiles');
    }
};

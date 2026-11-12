<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Buy 2 books, get 10% off your next order" (owner 2026-11-12). A reward rule says what earns a reward and what it
 * gives; the reward itself is an ordinary coupon locked to the customer, made when the earning order is paid (KHQR) or
 * delivered (cash), and linked to that order (one reward per order). `max_discount` caps a percentage coupon;
 * `reminded_at` marks the "ends in 3 days" email. Orders keep the KHQR discount they got.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reward_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->boolean('is_active')->default(false);
            $table->unsignedSmallInteger('min_books')->nullable();     // earns with at least this many books, or
            $table->decimal('min_amount', 10, 2)->nullable();          // with at least this much spent (after discounts)
            $table->decimal('percent_off', 5, 2);
            $table->decimal('max_discount', 10, 2)->nullable();        // cap in USD, null = no cap
            $table->unsignedSmallInteger('valid_days')->default(30);
            $table->timestamps();
        });

        Schema::table('coupons', function (Blueprint $table) {
            $table->foreignId('earned_by_order_id')->nullable()->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('reward_rule_id')->nullable()->constrained('reward_rules')->nullOnDelete();
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->timestamp('reminded_at')->nullable();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('khqr_discount_cents')->default(0);
        });

        // The owner's first rule: 2 or more books -> 10% off the next order, at most $5, for 30 days.
        DB::table('reward_rules')->insert([
            'name' => 'Buy 2 books, 10% off your next order',
            'is_active' => true,
            'min_books' => 2,
            'min_amount' => null,
            'percent_off' => '10.00',
            'max_discount' => '5.00',
            'valid_days' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('khqr_discount_cents'));
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reward_rule_id');
            $table->dropConstrainedForeignId('earned_by_order_id');
            $table->dropColumn(['max_discount', 'reminded_at']);
        });
        Schema::dropIfExists('reward_rules');
    }
};

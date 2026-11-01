<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic offers ("Buy 2, get 15% off"), applied without a code. One offer per order: the best one, and only when it
 * saves more than the customer's coupon (docs/superpowers/specs/2026-10-31-offers-design.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100); // internal: admin pages and reports
            $table->string('type', 30)->default('buy_n_percent');
            $table->string('applies_to', 10); // all | selected (see offer_targets)
            $table->smallInteger('min_quantity'); // N books
            $table->decimal('percent_off', 5, 2); // X %
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->integer('max_orders')->nullable(); // null = unlimited
            $table->integer('used_count')->default(0);
            $table->boolean('is_active')->default(true); // false = paused
            $table->timestamps();
        });

        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_type_check CHECK (type IN ('buy_n_percent'))");
        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_applies_to_check CHECK (applies_to IN ('all','selected'))");
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_min_quantity_check CHECK (min_quantity BETWEEN 2 AND 20)');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_percent_off_check CHECK (percent_off > 0 AND percent_off <= 50)');
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_dates_check CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};

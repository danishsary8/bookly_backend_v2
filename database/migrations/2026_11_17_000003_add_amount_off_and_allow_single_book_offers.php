<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add amount_off to offers (for fixed amount discounts e.g. Buy 2, save $3)
 * and update min_quantity check so buy_n_percent and buy_n_amount can start at 1 book (single-book clearance).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->decimal('amount_off', 10, 2)->nullable();
        });

        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_type_check');
        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_min_quantity_check');
        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_percent_off_check');

        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_type_check CHECK (type IN ('buy_n_percent', 'buy_n_amount', 'gift'))");
        DB::statement('ALTER TABLE offers ADD CONSTRAINT offers_min_quantity_check CHECK (min_quantity BETWEEN 1 AND 20)');
        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_percent_off_check CHECK (
            (type = 'buy_n_percent' AND percent_off > 0 AND percent_off <= 50)
            OR (type = 'buy_n_amount' AND percent_off = 0 AND (amount_off IS NOT NULL AND amount_off > 0))
            OR (type = 'gift' AND percent_off = 0)
        )");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_type_check');
        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_min_quantity_check');
        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_percent_off_check');

        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('amount_off');
        });

        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_type_check CHECK (type IN ('buy_n_percent','gift'))");
        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_min_quantity_check CHECK ((type = 'gift' AND min_quantity BETWEEN 1 AND 20) OR (type = 'buy_n_percent' AND min_quantity BETWEEN 2 AND 20))");
        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_percent_off_check CHECK ((type = 'gift' AND percent_off = 0 AND gift_id IS NOT NULL) OR (type = 'buy_n_percent' AND percent_off > 0 AND percent_off <= 50))");
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bundle offers (owner 2026-11-26): "any `min_quantity` of the chosen books for `bundle_price`", e.g. any 5 for $20.
 * Only set on offers of type `bundle_price` (at least 2 books, no percent). The type and percent checks gain the type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->decimal('bundle_price', 10, 2)->nullable()->after('amount_off');
        });
        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_type_check');
        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_percent_off_check');
        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_type_check CHECK (type IN ('buy_n_percent', 'buy_n_amount', 'gift', 'bundle_price'))");
        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_percent_off_check CHECK (
            (type = 'buy_n_percent' AND percent_off > 0 AND percent_off <= 50)
            OR (type = 'buy_n_amount' AND percent_off = 0 AND (amount_off IS NOT NULL AND amount_off > 0))
            OR (type = 'gift' AND percent_off = 0)
            OR (type = 'bundle_price' AND percent_off = 0 AND bundle_price IS NOT NULL AND bundle_price > 0 AND min_quantity >= 2)
        )");
    }

    public function down(): void
    {
        DB::statement("DELETE FROM offers WHERE type = 'bundle_price'");
        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_type_check');
        DB::statement('ALTER TABLE offers DROP CONSTRAINT IF EXISTS offers_percent_off_check');
        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_type_check CHECK (type IN ('buy_n_percent', 'buy_n_amount', 'gift'))");
        DB::statement("ALTER TABLE offers ADD CONSTRAINT offers_percent_off_check CHECK (
            (type = 'buy_n_percent' AND percent_off > 0 AND percent_off <= 50)
            OR (type = 'buy_n_amount' AND percent_off = 0 AND (amount_off IS NOT NULL AND amount_off > 0))
            OR (type = 'gift' AND percent_off = 0)
        )");
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn('bundle_price');
        });
    }
};

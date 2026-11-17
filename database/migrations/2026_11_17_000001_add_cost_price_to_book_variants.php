<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier cost per copy on a format (owner 2026-11-17, option B). Admins type it on the book form;
 * a restock with a unit cost updates it to the latest cost. Admin-only, like restock costs.
 * Existing formats take the cost of their latest restock that has one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_variants', function (Blueprint $table) {
            $table->decimal('cost_price_usd', 10, 2)->nullable()->after('price_usd');
        });

        DB::statement('UPDATE book_variants v SET cost_price_usd = m.unit_cost_usd FROM (
            SELECT DISTINCT ON (book_variant_id) book_variant_id, unit_cost_usd FROM inventory_movements
            WHERE unit_cost_usd IS NOT NULL ORDER BY book_variant_id, created_at DESC, id DESC
        ) m WHERE m.book_variant_id = v.id');
    }

    public function down(): void
    {
        Schema::table('book_variants', function (Blueprint $table) {
            $table->dropColumn('cost_price_usd');
        });
    }
};

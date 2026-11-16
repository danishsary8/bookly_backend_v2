<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Suppliers (owner 2026-11-16, Phase 3). Restocks keep their free-text `supplier` copy (history stays readable) and
 * gain `supplier_id`. Existing supplier names are copied into the table once and linked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('contact_name', 150)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 190)->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('supplier_id')->nullable()->after('supplier')->constrained('suppliers')->nullOnDelete();
            $table->index('supplier_id');
        });

        // Copy the names already typed on restocks (trimmed, case-insensitive duplicates merged) and link them.
        DB::statement("INSERT INTO suppliers (name, is_active, created_at, updated_at)
            SELECT MIN(TRIM(supplier)), true, NOW(), NOW() FROM inventory_movements
            WHERE supplier IS NOT NULL AND TRIM(supplier) <> '' GROUP BY LOWER(TRIM(supplier))");
        DB::statement('UPDATE inventory_movements m SET supplier_id = s.id FROM suppliers s
            WHERE m.supplier IS NOT NULL AND LOWER(TRIM(m.supplier)) = LOWER(s.name)');
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supplier_id');
        });
        Schema::dropIfExists('suppliers');
    }
};

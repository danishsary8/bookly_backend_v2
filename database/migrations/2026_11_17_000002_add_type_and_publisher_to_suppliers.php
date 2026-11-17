<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Publishers as suppliers (owner 2026-11-17, "go"): a supplier is a publisher (buying direct) or a distributor /
 * wholesaler (one account, many publishers). A publisher supplier can point at the catalogue publisher it is, so a
 * restock suggests the book's own publisher first. One supplier per publisher. Existing suppliers become distributors.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('type', 20)->default('distributor')->after('name');
            $table->foreignId('publisher_id')->nullable()->unique()->after('type')->constrained('publishers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('publisher_id');
            $table->dropColumn('type');
        });
    }
};

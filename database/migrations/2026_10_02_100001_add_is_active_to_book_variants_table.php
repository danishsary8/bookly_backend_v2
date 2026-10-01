<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('book_variants', function (Blueprint $table) {
            // Inactive variants are hidden from the catalog but kept for order history.
            $table->boolean('is_active')->default(true)->after('cover_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('book_variants', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};

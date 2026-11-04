<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap v3 phase 1 (owner 2026-11-05): two-level category tree, and who a book is for (age, grade, level). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('icon', 40)->nullable();
            $table->index(['parent_id', 'sort_order']);
        });
        Schema::table('books', function (Blueprint $table) {
            $table->unsignedTinyInteger('age_min')->nullable();
            $table->unsignedTinyInteger('age_max')->nullable();
            $table->unsignedTinyInteger('school_grade')->nullable();
            $table->string('level', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['age_min', 'age_max', 'school_grade', 'level']);
        });
        Schema::table('categories', function (Blueprint $table) {
            $table->dropIndex(['parent_id', 'sort_order']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['sort_order', 'icon']);
        });
    }
};

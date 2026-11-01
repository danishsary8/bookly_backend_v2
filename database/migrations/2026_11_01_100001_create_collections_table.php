<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Seasonal collections ("Rainy season reads"), owner 2026-11-01. New collections start hidden (is_active false). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 120)->unique();
            $table->string('title_en', 120);
            $table->string('title_km', 120)->nullable();
            $table->string('description_en', 300)->nullable();
            $table->string('description_km', 300)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->smallInteger('position')->default(0); // home-page order, lower first
            $table->timestamps();
        });
        DB::statement('ALTER TABLE collections ADD CONSTRAINT collections_dates_check CHECK (ends_at IS NULL OR starts_at IS NULL OR ends_at > starts_at)');
        DB::statement("ALTER TABLE collections ADD CONSTRAINT collections_slug_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('collections');
    }
};

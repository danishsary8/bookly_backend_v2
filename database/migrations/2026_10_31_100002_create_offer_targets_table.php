<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a "selected" offer covers: each row is exactly one book, category or series. Deleting the book, category or
 * series removes the row; an offer left with no rows matches nothing (shown as "No books" to admins).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offer_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('book_id')->nullable()->constrained('books')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->foreignId('series_id')->nullable()->constrained('series')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['offer_id', 'book_id']);
            $table->unique(['offer_id', 'category_id']);
            $table->unique(['offer_id', 'series_id']);
        });

        DB::statement('ALTER TABLE offer_targets ADD CONSTRAINT offer_targets_one_check CHECK (num_nonnulls(book_id, category_id, series_id) = 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_targets');
    }
};

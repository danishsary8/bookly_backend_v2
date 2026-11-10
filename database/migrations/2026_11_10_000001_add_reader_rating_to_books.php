<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reader rating: a public score for a book that staff enter with its source (e.g. 4.3, "Goodreads").
 * Shown on the storefront, labelled with the source, until the book has its own customer reviews.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->decimal('reader_rating', 2, 1)->nullable();
            $table->string('reader_rating_source', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('books', fn (Blueprint $table) => $table->dropColumn(['reader_rating', 'reader_rating_source']));
    }
};

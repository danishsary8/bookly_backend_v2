<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The hand-picked books of a collection, in the order the admin set. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_books', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained('collections')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->smallInteger('position')->default(0);
            $table->primary(['collection_id', 'book_id']);
            $table->index(['collection_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_books');
    }
};

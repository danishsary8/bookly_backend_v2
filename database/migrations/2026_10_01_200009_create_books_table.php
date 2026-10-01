<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('publisher_id')->nullable()->constrained('publishers')->nullOnDelete();
            $table->foreignId('series_id')->nullable()->constrained('series')->nullOnDelete();
            $table->smallInteger('series_order')->nullable();
            $table->string('language', 50)->default('English');
            $table->integer('page_count')->nullable();
            $table->date('publish_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('publisher_id');
            $table->index('series_id');
        });

        // Generated tsvector for full-text search over title + description.
        DB::statement("ALTER TABLE books ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(title, '') || ' ' || coalesce(description, ''))) STORED");
        DB::statement('CREATE INDEX books_search_vector_gin ON books USING GIN (search_vector)');
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};

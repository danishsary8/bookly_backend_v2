<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Book search also matches author, series and category names and the unstemmed title (Book::scopeSearch).
 * These expression indexes keep those lookups fast. Indexes only: no table or column changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX books_title_simple_search ON books USING GIN (to_tsvector('simple', title))");
        DB::statement("CREATE INDEX authors_name_search ON authors USING GIN (to_tsvector('simple', name))");
        DB::statement("CREATE INDEX series_name_search ON series USING GIN (to_tsvector('english', name))");
        DB::statement("CREATE INDEX categories_name_search ON categories USING GIN (to_tsvector('english', name))");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS books_title_simple_search');
        DB::statement('DROP INDEX IF EXISTS authors_name_search');
        DB::statement('DROP INDEX IF EXISTS series_name_search');
        DB::statement('DROP INDEX IF EXISTS categories_name_search');
    }
};

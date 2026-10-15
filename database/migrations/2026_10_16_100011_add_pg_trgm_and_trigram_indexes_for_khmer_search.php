<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX IF NOT EXISTS books_title_trgm_idx ON books USING gin (title gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS authors_name_trgm_idx ON authors USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS series_name_trgm_idx ON series USING gin (name gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS categories_name_trgm_idx ON categories USING gin (name gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS books_title_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS authors_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS series_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS categories_name_trgm_idx');

        // Note: The pg_trgm extension is intentionally left in place because dropping extensions
        // can affect other tables/databases in shared or managed environments or require superuser privileges.
    }
};

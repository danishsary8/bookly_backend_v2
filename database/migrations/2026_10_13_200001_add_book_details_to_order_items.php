<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Order lines keep the book details they were bought with (owner decision 2026-10-13), so renaming a book or changing
 * a SKU never changes an old invoice, packing slip, order page or email. Existing lines are filled once from today's
 * book (deleted books included); only empty columns are filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('title')->nullable();
            $table->string('format', 20)->nullable();
            $table->string('sku', 50)->nullable();
            $table->string('isbn', 20)->nullable();
            $table->jsonb('authors')->nullable();
        });

        DB::statement(<<<'SQL'
            UPDATE order_items oi
            SET title = b.title,
                format = v.format,
                sku = v.sku,
                isbn = v.isbn,
                authors = (
                    SELECT COALESCE(jsonb_agg(a.name ORDER BY a.id), '[]'::jsonb)
                    FROM book_authors ba JOIN authors a ON a.id = ba.author_id
                    WHERE ba.book_id = b.id
                )
            FROM book_variants v
            JOIN books b ON b.id = v.book_id
            WHERE v.id = oi.book_variant_id AND oi.title IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['title', 'format', 'sku', 'isbn', 'authors']);
        });
    }
};

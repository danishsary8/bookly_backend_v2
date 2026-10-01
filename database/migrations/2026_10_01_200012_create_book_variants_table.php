<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->string('format', 20);
            $table->string('isbn', 20)->nullable()->unique();
            $table->string('sku', 50)->unique();
            $table->decimal('price_usd', 10, 2);
            $table->integer('stock_quantity')->default(0);
            $table->integer('low_stock_threshold')->default(5);
            $table->string('cover_image_url', 500)->nullable();
            $table->timestamps();

            $table->unique(['book_id', 'format']);
        });

        DB::statement("ALTER TABLE book_variants ADD CONSTRAINT book_variants_format_check CHECK (format IN ('hardcover','paperback','ebook','audiobook'))");
        DB::statement('ALTER TABLE book_variants ADD CONSTRAINT book_variants_price_check CHECK (price_usd >= 0)');
        DB::statement('ALTER TABLE book_variants ADD CONSTRAINT book_variants_stock_check CHECK (stock_quantity >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('book_variants');
    }
};

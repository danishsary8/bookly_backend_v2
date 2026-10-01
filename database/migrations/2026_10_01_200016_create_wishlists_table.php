<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['customer_id', 'book_id']);
            $table->index('book_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wishlists');
    }
};

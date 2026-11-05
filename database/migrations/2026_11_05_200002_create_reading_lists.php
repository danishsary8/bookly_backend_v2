<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('title', 100);
            $table->text('description')->nullable();
            $table->string('slug', 40)->unique();
            $table->boolean('is_public')->default(false);
            $table->boolean('is_staff_pick')->default(false);
            $table->timestamps();
            $table->index(['is_public', 'is_staff_pick']);
        });
        Schema::create('reading_list_books', function (Blueprint $table) {
            $table->foreignId('list_id')->constrained('reading_lists')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->integer('position');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->primary(['list_id', 'book_id']);
        });
        DB::statement('ALTER TABLE reading_list_books ADD CONSTRAINT reading_list_position_check CHECK (position >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_list_books');
        Schema::dropIfExists('reading_lists');
    }
};

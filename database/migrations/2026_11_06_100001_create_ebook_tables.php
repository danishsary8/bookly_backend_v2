<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Roadmap v3 phase 2 (owner 2026-11-06): browser-only ebook reader. See docs ebooks design. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ebook_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->unique()->constrained('books')->cascadeOnDelete();
            $table->string('path', 255); // folder on the private ebook disk
            $table->unsignedInteger('size_bytes')->default(0);
            $table->unsignedSmallInteger('page_count')->default(0);
            $table->unsignedSmallInteger('pages_rendered')->default(0);
            $table->unsignedSmallInteger('preview_pages')->nullable(); // staff override; null = 10%
            $table->string('status', 20)->default('processing'); // processing | ready | failed
            $table->string('error', 500)->nullable();
            $table->foreignId('uploaded_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('ebook_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->char('device_hash', 64);
            $table->string('name', 100);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'removed_at']);
        });

        Schema::create('ebook_progress', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->unsignedSmallInteger('page')->default(1);
            $table->timestamp('updated_at')->nullable();
            $table->primary(['customer_id', 'book_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ebook_progress');
        Schema::dropIfExists('ebook_devices');
        Schema::dropIfExists('ebook_files');
    }
};

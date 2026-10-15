<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('subject_en', 255);
            $table->string('subject_km', 255)->nullable();
            $table->text('body_en');
            $table->text('body_km')->nullable();
            $table->json('book_ids')->default('[]');
            $table->string('status', 20)->default('draft'); // draft, sending, sent
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->integer('recipients_total')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_campaigns');
    }
};

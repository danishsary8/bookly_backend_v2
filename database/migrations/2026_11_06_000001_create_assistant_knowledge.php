<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question_en', 300);
            $table->string('question_km', 300);
            $table->text('answer_en');
            $table->text('answer_km');
            $table->jsonb('keywords');
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamps();
        });
        Schema::create('assistant_unanswered', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('question', 300);
            $table->unsignedBigInteger('count')->default(1);
            $table->timestamp('last_seen')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_unanswered');
        Schema::dropIfExists('assistant_faqs');
    }
};

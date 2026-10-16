<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 30);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('email', 190);
            $table->string('locale', 5)->default('en');
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->smallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_outbox');
    }
};

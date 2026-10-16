<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_customer_tag', function (Blueprint $table) {
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('customer_tag_id')->constrained('customer_tags')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->primary(['customer_id', 'customer_tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_customer_tag');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->smallInteger('stars');
            $table->string('comment', 500)->nullable();
            $table->boolean('is_comment_hidden')->default(false);
            $table->timestamp('created_at')->useCurrent()->index();
        });
        DB::statement('ALTER TABLE order_ratings ADD CONSTRAINT order_rating_stars CHECK (stars BETWEEN 1 AND 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_ratings');
    }
};

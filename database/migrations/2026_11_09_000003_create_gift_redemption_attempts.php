<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_redemption_attempts', function (Blueprint $table) {
            $table->foreignId('customer_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_redemption_attempts');
    }
};

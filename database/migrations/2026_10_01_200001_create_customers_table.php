<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 190)->unique();
            $table->string('password_hash')->nullable(); // null when the account is social-login-only
            $table->string('phone', 30)->nullable();
            $table->string('google_id', 190)->nullable()->unique();
            $table->string('facebook_id', 190)->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};

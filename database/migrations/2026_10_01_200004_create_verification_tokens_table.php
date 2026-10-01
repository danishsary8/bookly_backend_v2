<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('user_type', 20);
            $table->unsignedBigInteger('user_id');
            $table->string('purpose', 20);
            $table->string('code_hash'); // OTP is stored hashed, never plain
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_type', 'user_id', 'purpose']);
        });

        DB::statement("ALTER TABLE verification_tokens ADD CONSTRAINT verification_tokens_user_type_check CHECK (user_type IN ('customer','staff'))");
        DB::statement("ALTER TABLE verification_tokens ADD CONSTRAINT verification_tokens_purpose_check CHECK (purpose IN ('email_verify','password_reset'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_tokens');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('email', 190)->unique();
            $table->string('password_hash');
            $table->string('role', 20);
            $table->text('two_factor_secret')->nullable(); // stored encrypted by the model cast
            $table->boolean('two_factor_enabled')->default(false);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE staff_users ADD CONSTRAINT staff_users_role_check CHECK (role IN ('admin','staff'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_users');
    }
};

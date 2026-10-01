<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Deactivated accounts cannot log in, but their history (orders, audit logs) stays intact.
        Schema::table('staff_users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('two_factor_enabled');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('staff_users', fn (Blueprint $table) => $table->dropColumn('is_active'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('is_active'));
    }
};

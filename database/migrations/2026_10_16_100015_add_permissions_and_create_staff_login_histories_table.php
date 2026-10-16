<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('staff_users', 'permissions')) {
            Schema::table('staff_users', function (Blueprint $table) {
                $table->jsonb('permissions')->nullable()->after('role');
            });
        }

        if (! Schema::hasColumn('personal_access_tokens', 'ip_address')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->string('ip_address', 45)->nullable()->after('abilities');
            });
        }

        if (! Schema::hasTable('staff_login_histories')) {
            Schema::create('staff_login_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('staff_user_id')->constrained('staff_users')->cascadeOnDelete();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('device', 100)->nullable();
                $table->string('status', 30); // success, failed_password, failed_2fa, deactivated
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_login_histories');

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('ip_address');
        });

        Schema::table('staff_users', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};

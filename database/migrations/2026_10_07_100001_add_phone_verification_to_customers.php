<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // The number in international form (+85512345678), set once the customer proves they own it
            // or while they wait for its code. `phone` stays the free-text contact number.
            $table->string('phone_e164', 20)->nullable()->unique()->after('phone');
            $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
        });

        // Facebook sign-ups give a phone number instead; their email is optional.
        Schema::table('customers', function (Blueprint $table) {
            $table->string('email', 190)->nullable()->change();
        });

        DB::statement('ALTER TABLE verification_tokens DROP CONSTRAINT verification_tokens_purpose_check');
        DB::statement("ALTER TABLE verification_tokens ADD CONSTRAINT verification_tokens_purpose_check CHECK (purpose IN ('email_verify','password_reset','phone_verify'))");
    }

    public function down(): void
    {
        DB::table('verification_tokens')->where('purpose', 'phone_verify')->delete();
        DB::statement('ALTER TABLE verification_tokens DROP CONSTRAINT verification_tokens_purpose_check');
        DB::statement("ALTER TABLE verification_tokens ADD CONSTRAINT verification_tokens_purpose_check CHECK (purpose IN ('email_verify','password_reset'))");

        Schema::table('customers', function (Blueprint $table) {
            $table->string('email', 190)->nullable(false)->change();
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['phone_e164']);
            $table->dropColumn(['phone_e164', 'phone_verified_at']);
        });
    }
};

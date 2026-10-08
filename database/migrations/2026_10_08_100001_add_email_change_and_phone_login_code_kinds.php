<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two more kinds of one-time code (owner OK, 2026-10-07): `email_change` (a code sent to the new address
 * when a customer adds or changes their email) and `phone_login` (signing in with a phone number and a
 * Telegram code). Keeping them apart means a code can only ever do the job it was sent for.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE verification_tokens DROP CONSTRAINT verification_tokens_purpose_check');
        DB::statement("ALTER TABLE verification_tokens ADD CONSTRAINT verification_tokens_purpose_check CHECK (purpose IN ('email_verify','password_reset','phone_verify','email_change','phone_login'))");
    }

    public function down(): void
    {
        DB::table('verification_tokens')->whereIn('purpose', ['email_change', 'phone_login'])->delete();
        DB::statement('ALTER TABLE verification_tokens DROP CONSTRAINT verification_tokens_purpose_check');
        DB::statement("ALTER TABLE verification_tokens ADD CONSTRAINT verification_tokens_purpose_check CHECK (purpose IN ('email_verify','password_reset','phone_verify'))");
    }
};

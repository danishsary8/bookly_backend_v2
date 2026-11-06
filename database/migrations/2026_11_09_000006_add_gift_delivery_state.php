<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->timestamp('code_revealed_at')->nullable();
            $table->timestamp('email_claimed_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->timestamp('email_failed_at')->nullable();
            $table->string('locale', 2)->default('en');
        });
    }

    public function down(): void
    {
        Schema::table('gift_cards', fn (Blueprint $table) => $table->dropColumn(['code_revealed_at', 'email_claimed_at', 'email_sent_at', 'email_failed_at', 'locale']));
    }
};

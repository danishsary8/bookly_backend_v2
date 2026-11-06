<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_card_payments', fn (Blueprint $table) => $table->timestamp('last_checked_at')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('gift_card_payments', fn (Blueprint $table) => $table->dropColumn('last_checked_at'));
    }
};

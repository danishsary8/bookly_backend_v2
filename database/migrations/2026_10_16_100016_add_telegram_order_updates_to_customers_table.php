<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'telegram_chat_id')) {
                $table->unsignedBigInteger('telegram_chat_id')->nullable()->index();
            }
            if (! Schema::hasColumn('customers', 'telegram_order_updates')) {
                $table->boolean('telegram_order_updates')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'telegram_chat_id')) {
                $table->dropIndex(['telegram_chat_id']);
                $table->dropColumn('telegram_chat_id');
            }
            if (Schema::hasColumn('customers', 'telegram_order_updates')) {
                $table->dropColumn('telegram_order_updates');
            }
        });
    }
};

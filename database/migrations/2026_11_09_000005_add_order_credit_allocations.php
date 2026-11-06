<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->bigInteger('credit_used_cents')->default(0);
        });
        Schema::table('returns', function (Blueprint $table) {
            $table->bigInteger('credit_refund_cents')->default(0);
        });
        DB::statement('ALTER TABLE orders ADD CONSTRAINT order_credit_check CHECK (credit_used_cents >= 0 AND credit_used_cents <= total_amount * 100)');
        DB::statement('ALTER TABLE returns ADD CONSTRAINT return_credit_check CHECK (credit_refund_cents >= 0)');
    }

    public function down(): void
    {
        Schema::table('returns', fn (Blueprint $table) => $table->dropColumn('credit_refund_cents'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('credit_used_cents'));
    }
};

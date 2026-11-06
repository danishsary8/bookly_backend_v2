<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable();
            $table->unique(['buyer_customer_id', 'idempotency_key']);
        });
        Schema::create('gift_card_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gift_card_id')->unique()->constrained()->restrictOnDelete();
            $table->char('provider_transaction_id', 32)->unique();
            $table->text('qr_payload');
            $table->timestamp('expires_at');
            $table->string('status', 20)->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
        DB::statement("ALTER TABLE gift_card_payments ADD CONSTRAINT gift_card_payments_status_check CHECK (status IN ('pending', 'succeeded', 'expired', 'late'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_card_payments');
        Schema::table('gift_cards', function (Blueprint $table) {
            $table->dropUnique(['buyer_customer_id', 'idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};

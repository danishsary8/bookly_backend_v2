<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('redeemed_by_customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->string('recipient_name', 120);
            $table->string('recipient_email', 190);
            $table->string('message', 300)->nullable();
            $table->date('send_on');
            $table->bigInteger('amount_cents');
            $table->string('status', 24)->default('pending_payment');
            $table->char('code_hash', 64)->nullable()->unique();
            $table->char('last_four', 4)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'send_on']);
        });
        DB::statement("ALTER TABLE gift_cards ADD CONSTRAINT gift_cards_status_check CHECK (status IN ('pending_payment', 'active', 'redeemed', 'cancelled', 'expired'))");
        DB::statement('ALTER TABLE gift_cards ADD CONSTRAINT gift_cards_amount_check CHECK (amount_cents BETWEEN 500 AND 20000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_cards');
    }
};

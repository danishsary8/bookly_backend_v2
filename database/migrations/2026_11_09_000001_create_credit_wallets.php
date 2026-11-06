<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->restrictOnDelete();
            $table->bigInteger('balance_cents')->default(0);
            $table->timestamps();
        });
        DB::statement('ALTER TABLE credit_wallets ADD CONSTRAINT credit_wallets_balance_check CHECK (balance_cents >= 0)');

        Schema::create('credit_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_wallet_id')->constrained()->restrictOnDelete();
            $table->string('reference', 120);
            $table->bigInteger('amount_cents');
            $table->bigInteger('balance_after_cents');
            $table->timestamp('created_at');
            $table->unique(['credit_wallet_id', 'reference']);
        });
        DB::statement('ALTER TABLE credit_ledger_entries ADD CONSTRAINT credit_ledger_amount_check CHECK (amount_cents <> 0 AND balance_after_cents >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger_entries');
        Schema::dropIfExists('credit_wallets');
    }
};

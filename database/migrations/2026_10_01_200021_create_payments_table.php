<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('provider', 20);
            $table->string('provider_transaction_id', 190)->nullable()->unique();
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('status', 20);
            $table->jsonb('raw_response')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_provider_check CHECK (provider IN ('stripe','paypal','bakong','cod'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending','succeeded','failed','refunded'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

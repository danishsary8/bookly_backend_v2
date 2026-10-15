<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->timestamp('refunded_at')->nullable()->after('refund_amount');
            $table->string('refund_method', 30)->nullable()->after('refunded_at');
            $table->decimal('refund_amount_usd', 10, 2)->nullable()->after('refund_method');
            $table->string('refund_reference')->nullable()->after('refund_amount_usd');
            $table->foreignId('refunded_by_id')->nullable()->after('refund_reference')->constrained('staff_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_by_id');
            $table->dropColumn([
                'refunded_at',
                'refund_method',
                'refund_amount_usd',
                'refund_reference',
            ]);
        });
    }
};

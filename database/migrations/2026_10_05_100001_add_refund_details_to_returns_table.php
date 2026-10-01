<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            // Saved when the return is refunded, so the amount stays fixed even if prices change later.
            $table->decimal('refund_amount', 10, 2)->nullable()->after('status');
            $table->text('staff_note')->nullable()->after('refund_amount');
            $table->foreignId('handled_by_staff_id')->nullable()->after('staff_note')->constrained('staff_users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('handled_by_staff_id');
            $table->dropColumn(['refund_amount', 'staff_note']);
        });
    }
};

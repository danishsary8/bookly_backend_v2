<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->text('note')->nullable();
            $table->string('supplier', 150)->nullable();
            $table->decimal('unit_cost_usd', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropColumn(['unit_cost_usd', 'supplier', 'note']);
        });
    }
};

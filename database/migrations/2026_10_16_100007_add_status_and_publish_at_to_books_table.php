<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('status', 20)->default('live')->after('description');
            $table->timestamp('publish_at')->nullable()->after('status');
            $table->index(['status', 'publish_at']);
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex(['status', 'publish_at']);
            $table->dropColumn(['status', 'publish_at']);
        });
    }
};

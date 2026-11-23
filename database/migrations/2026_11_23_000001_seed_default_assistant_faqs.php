<?php

use Database\Seeders\AssistantFaqSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assistant_faqs')) {
            return;
        }

        (new AssistantFaqSeeder)->run();
    }

    public function down(): void
    {
        // Keep seeded/staff-edited FAQs intact on rollback to avoid losing knowledge base entries.
    }
};

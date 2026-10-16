<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190);
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('locale', 5)->default('en');
            $table->string('status', 20)->default('pending'); // pending, confirmed, unsubscribed
            $table->string('confirm_token_hash', 64)->nullable();
            // Looked up by hash; the token itself is kept encrypted (APP_KEY) only to put it into each newsletter email.
            $table->string('unsubscribe_token_hash', 64)->unique();
            $table->text('unsubscribe_token');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('confirm_token_hash');
        });

        DB::statement('CREATE UNIQUE INDEX newsletter_subscribers_lower_email_unique ON newsletter_subscribers (LOWER(email))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS newsletter_subscribers_lower_email_unique');
        Schema::dropIfExists('newsletter_subscribers');
    }
};

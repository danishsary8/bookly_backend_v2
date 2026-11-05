<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('guest_name', 100)->nullable();
            $table->string('guest_email')->nullable();
            $table->string('locale', 2)->default('en');
            $table->string('topic', 15);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->string('status', 10)->default('open');
            $table->foreignId('assigned_staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('closed_at')->nullable()->index();
            $table->timestamp('last_customer_seen_at')->nullable();
            $table->timestamp('last_reply_email_at')->nullable();
            $table->unsignedBigInteger('last_email_message_id')->default(0);
            $table->timestamp('anonymised_at')->nullable();
            $table->unsignedBigInteger('version')->default(1);
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->index(['assigned_staff_id', 'status']);
        });
        DB::statement("ALTER TABLE support_conversations ADD CONSTRAINT support_topic_check CHECK (topic IN ('order','payment','ebook','delivery','other')), ADD CONSTRAINT support_status_check CHECK (status IN ('open','pending','closed'))");
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $table->string('sender', 10);
            $table->foreignId('staff_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('read_at')->nullable();
            $table->index(['conversation_id', 'id']);
        });
        DB::statement("ALTER TABLE support_messages ADD CONSTRAINT support_sender_check CHECK (sender IN ('customer','staff','system')), ADD CONSTRAINT support_body_check CHECK (char_length(body) BETWEEN 1 AND 2000), ADD CONSTRAINT support_internal_check CHECK (NOT is_internal OR sender = 'staff')");
        // Polling only counts unread public messages; do not scan years of read history on each poll.
        DB::statement('CREATE INDEX support_unread_messages_idx ON support_messages (conversation_id, sender, id) WHERE read_at IS NULL AND NOT is_internal');
        Schema::create('support_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained('support_conversations')->cascadeOnDelete();
            $table->smallInteger('stars');
            $table->string('comment', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        DB::statement('ALTER TABLE support_ratings ADD CONSTRAINT support_stars_check CHECK (stars BETWEEN 1 AND 5)');
        Schema::create('support_quick_replies', function (Blueprint $table) {
            $table->id();
            $table->string('title', 100);
            $table->text('body_en');
            $table->text('body_km');
            $table->timestamps();
        });
        Schema::create('support_telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $table->bigInteger('chat_id');
            $table->bigInteger('message_id');
            $table->unique(['chat_id', 'message_id']);
        });
        Schema::create('support_telegram_updates', function (Blueprint $table) {
            $table->bigInteger('update_id')->primary();
            $table->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::table('staff_users', fn (Blueprint $table) => $table->bigInteger('support_telegram_user_id')->nullable()->unique());

        // Preserve explicit role customisation, granting support only to staff who already handle orders.
        DB::table('staff_users')->whereNotNull('permissions')->orderBy('id')->each(function ($staff) {
            $permissions = json_decode($staff->permissions, true);
            if (in_array('orders:manage', $permissions, true) && ! in_array('support:manage', $permissions, true)) {
                DB::table('staff_users')->where('id', $staff->id)->update(['permissions' => json_encode([...$permissions, 'support:manage'])]);
            }
        });
        DB::table('personal_access_tokens')->where('tokenable_type', 'App\\Models\\StaffUser')->orderBy('id')->each(function ($token) {
            $abilities = json_decode($token->abilities, true);
            if (in_array('orders:manage', $abilities, true) && ! in_array('support:manage', $abilities, true)) {
                DB::table('personal_access_tokens')->where('id', $token->id)->update(['abilities' => json_encode([...$abilities, 'support:manage'])]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('staff_users', fn (Blueprint $table) => $table->dropColumn('support_telegram_user_id'));
        foreach (['support_telegram_updates', 'support_telegram_messages', 'support_quick_replies', 'support_ratings', 'support_messages', 'support_conversations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

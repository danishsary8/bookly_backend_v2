<?php

namespace App\Services\Support;

use App\Models\StaffUser;
use App\Models\SupportConversation;
use App\Services\Telegram\TelegramBot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SupportTelegram
{
    public function alert(SupportConversation $conversation): void
    {
        $chat = (string) config('services.ops_alerts.staff_chat_id');
        if ($chat === '' || ! TelegramBot::enabled()) {
            return;
        }
        try {
            // Use the existing Bookly bot: its webhook already verifies a secret before this handler runs.
            $result = app(TelegramBot::class)->call('sendMessage', ['chat_id' => $chat,
                'text' => __('support.telegram_alert', ['id' => $conversation->id, 'topic' => $conversation->topic, 'url' => rtrim((string) config('shop.url'), '/').'/admin/support?conversation='.$conversation->id]),
                'reply_markup' => ['force_reply' => true, 'selective' => true],
            ]);
            if (isset($result['message_id'])) {
                DB::table('support_telegram_messages')->insertOrIgnore(['conversation_id' => $conversation->id, 'chat_id' => $chat, 'message_id' => $result['message_id']]);
            }
        } catch (\Throwable) {
            Log::warning('Support group alert could not be delivered.', ['conversation_id' => $conversation->id]);
        }
    }

    public function receive(array $message, int $updateId): bool
    {
        $chat = (string) ($message['chat']['id'] ?? '');
        if ($chat === '' || $chat !== (string) config('services.ops_alerts.staff_chat_id') || ! isset($message['reply_to_message']['message_id'])) {
            return false;
        }
        $staff = StaffUser::where('support_telegram_user_id', $message['from']['id'] ?? 0)->where('is_active', true)->first();
        if (! $staff || ! $staff->hasPermission('support:manage') || ($message['from']['is_bot'] ?? false)) {
            return true;
        }
        $mapping = DB::table('support_telegram_messages')->where('chat_id', $chat)->where('message_id', $message['reply_to_message']['message_id'])->first();
        $validation = Validator::make(['message' => $message['text'] ?? null], ['message' => ['required', 'string', 'max:2000', 'not_regex:/[<>]/']]);
        if (! $mapping || $validation->fails() || $updateId < 0) {
            return true;
        }
        DB::transaction(function () use ($mapping, $message, $staff, $updateId) {
            $conversation = SupportConversation::lockForUpdate()->find($mapping->conversation_id);
            if (! $conversation || $conversation->status === 'closed' || $conversation->anonymised_at) {
                return;
            }
            if (! DB::table('support_telegram_updates')->insertOrIgnore(['update_id' => $updateId, 'conversation_id' => $conversation->id, 'created_at' => now()])) {
                return; // Telegram retries are idempotent, including the outbound email.
            }
            app(SupportService::class)->message($conversation, $message['text'], $staff);
        });

        return true;
    }
}

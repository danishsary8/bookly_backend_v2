<?php

namespace App\Console\Commands;

use App\Models\SupportConversation;
use App\Services\Support\SupportMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SendSupportEmails extends Command
{
    protected $signature = 'support:send-emails';

    protected $description = 'Deliver unread support replies after the per-conversation email cooldown';

    public function handle(SupportMail $mail): int
    {
        SupportConversation::whereNull('anonymised_at')
            ->where(fn ($q) => $q->whereNull('last_reply_email_at')->orWhere('last_reply_email_at', '<=', now()->subMinutes(30)))
            ->whereHas('messages', fn ($q) => $q->where('sender', 'staff')->where('is_internal', false)->whereNull('read_at')->whereColumn('support_messages.id', '>', 'support_conversations.last_email_message_id'))
            ->orderBy('id')->chunkById(100, function ($rows) use ($mail) {
                foreach ($rows as $row) {
                    DB::transaction(function () use ($row, $mail) {
                        $locked = SupportConversation::lockForUpdate()->find($row->id);
                        if ($locked && ! $locked->anonymised_at) {
                            $mail->send($locked);
                        }
                    });
                }
            });

        return self::SUCCESS;
    }
}

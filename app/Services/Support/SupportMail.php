<?php

namespace App\Services\Support;

use App\Mail\SupportReplyMail;
use App\Models\SupportConversation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Traits\Localizable;

class SupportMail
{
    use Localizable;

    public function send(SupportConversation $conversation, bool $confirmation = false, ?string $replyBody = null): void
    {
        $email = $conversation->guest_email ?? $conversation->customer?->email;
        if (! $email) {
            return;
        }
        // Claim the mail slot while the caller holds the conversation lock; simultaneous replies cannot flood mail.
        if (! $confirmation) {
            if ($conversation->last_reply_email_at?->gt(now()->subMinutes(30)) || ($conversation->customer_id && $conversation->last_customer_seen_at?->gt(now()->subMinute()))) {
                return;
            }
            $conversation->last_reply_email_at = now();
            $pending = $conversation->messages()->where('sender', 'staff')->where('is_internal', false)->where('id', '>', $conversation->last_email_message_id)->orderBy('id')->get();
            if ($pending->isEmpty()) {
                return;
            }
            $replyBody = $pending->pluck('body')->implode("\n\n");
            $conversation->last_email_message_id = $pending->last()->id;
            $conversation->save();
        }
        $id = $conversation->id;
        $locale = $conversation->locale;
        $guest = $conversation->customer_id === null;
        DB::afterCommit(function () use ($email, $id, $locale, $confirmation, $replyBody, $guest) {
            try {
                $this->withLocale($locale, function () use ($email, $id, $confirmation, $replyBody, $guest) {
                    $body = $confirmation ? __('support.confirmation', ['id' => $id]) : __('support.reply_email', ['id' => $id]);
                    if ($guest && ! $confirmation) {
                        $body = __('support.subject')." #{$id}\n\n".$replyBody;
                    }
                    Mail::to($email)->send(new SupportReplyMail($body));
                });
            } catch (\Throwable) {
                // Deliberately omit exception, address and body: provider errors can contain personal data.
                Log::warning('Support email could not be delivered.', ['conversation_id' => $id]);
            }
        });
    }
}

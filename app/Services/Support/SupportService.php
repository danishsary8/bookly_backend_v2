<?php

namespace App\Services\Support;

use App\Models\Customer;
use App\Models\StaffUser;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupportService
{
    public function start(?Customer $customer, array $input): SupportConversation
    {
        $conversation = DB::transaction(function () use ($customer, $input) {
            $conversation = SupportConversation::create([
                'customer_id' => $customer?->id, 'guest_name' => $customer ? null : $input['name'],
                'guest_email' => $customer ? null : $input['email'], 'locale' => app()->getLocale(),
                'topic' => $input['topic'] ?? 'other', 'order_id' => $input['order_id'] ?? null,
                'last_customer_seen_at' => $customer ? now() : null,
            ]);
            $this->message($conversation, $input['message'], null);
            if (! $customer) {
                app(SupportMail::class)->send($conversation, confirmation: true);
            }

            return $conversation;
        });

        return $conversation->fresh();
    }

    /** All writers lock the same conversation row, including Telegram, status, rating and mail-slot claims. */
    public function message(SupportConversation $conversation, string $body, ?StaffUser $staff, bool $internal = false): SupportMessage
    {
        return DB::transaction(function () use ($conversation, $body, $staff, $internal) {
            $locked = SupportConversation::lockForUpdate()->findOrFail($conversation->id);
            if ($locked->status === 'closed' || $locked->anonymised_at) {
                throw ValidationException::withMessages(['message' => __('support.closed')]);
            }
            $message = $locked->messages()->create(['sender' => $staff ? 'staff' : 'customer', 'staff_id' => $staff?->id, 'body' => $body, 'is_internal' => $internal]);
            $locked->version++;
            if (! $internal) {
                $locked->last_message_at = now();
                $locked->status = $staff ? 'pending' : 'open';
            }
            $locked->save();
            if ($staff && ! $internal) {
                app(SupportMail::class)->send($locked, replyBody: $body);
            } elseif (! $staff) {
                DB::afterCommit(fn () => app(SupportTelegram::class)->alert($locked));
            }

            return $message;
        });
    }

    public function markRead(SupportConversation $conversation, bool $staff): void
    {
        DB::transaction(function () use ($conversation, $staff) {
            $locked = SupportConversation::lockForUpdate()->findOrFail($conversation->id);
            $changed = $locked->messages()->where('sender', $staff ? 'customer' : 'staff')->where('is_internal', false)->whereNull('read_at')->update(['read_at' => now()]);
            if (! $staff) {
                $locked->last_customer_seen_at = now();
            }
            if ($changed) {
                $locked->version++;
            }
            $locked->save();
        });
    }
}

<?php

namespace App\Services\Credit;

use App\Mail\GiftCardMail;
use App\Models\GiftCard;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GiftDelivery
{
    public function send(int $id): void
    {
        if (! config('shop.gift_cards_enabled') || config('mail.default') === 'log') {
            return; // A redeemable code must never be sent through the logging mail transport.
        }
        // Claim before touching the mail provider. Ambiguous failures require an explicit staff resend.
        $card = DB::transaction(function () use ($id) {
            $card = GiftCard::lockForUpdate()->find($id);
            if (! $card || $card->status !== 'active' || $card->paid_at === null || $card->email_claimed_at !== null
                || $card->send_on > now(config('shop.timezone'))->toDateString() || now()->greaterThanOrEqualTo($card->expires_at)) {
                return null;
            }
            $card->update(['email_claimed_at' => now()]);

            return $card;
        });
        if (! $card) {
            return;
        }
        try {
            Mail::to($card->recipient_email)->locale($card->locale)->send(new GiftCardMail($card->recipient_name, Money::format((int) $card->amount_cents), app(GiftCode::class)->forId($card->id), substr($card->expires_at, 0, 10), $card->message));
            $card->update(['email_sent_at' => now(), 'email_failed_at' => null]);
        } catch (\Throwable) {
            $card->update(['email_failed_at' => now()]);
            Log::warning('Gift email delivery needs staff review.', ['gift_card_id' => $id]);
        }
    }
}

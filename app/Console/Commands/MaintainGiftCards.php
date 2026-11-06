<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\GiftCard;
use App\Services\Credit\GiftDelivery;
use App\Services\Credit\GiftPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MaintainGiftCards extends Command
{
    protected $signature = 'gifts:maintain';

    protected $description = 'Reconcile pending gift payments, deliver due cards and expire unused cards';

    public function handle(GiftDelivery $delivery, GiftPayment $payment): int
    {
        if (! config('shop.gift_cards_enabled')) {
            return self::SUCCESS;
        }
        // Bounded catch-up also checks recently expired QRs for late money requiring a manual refund.
        DB::table('gift_cards')->join('gift_card_payments', 'gift_card_payments.gift_card_id', '=', 'gift_cards.id')
            ->whereIn('gift_card_payments.status', ['pending', 'expired'])->where('gift_cards.created_at', '>=', now()->subDay())
            ->orderByRaw('gift_card_payments.last_checked_at ASC NULLS FIRST')->orderBy('gift_cards.id')->limit(50)
            ->get(['gift_cards.id', 'gift_cards.buyer_customer_id'])->each(function ($card) use ($payment) {
                $buyer = Customer::find($card->buyer_customer_id);
                if ($buyer) {
                    $payment->refresh($buyer, $card->id);
                }
            });
        GiftCard::where('status', 'active')->where('expires_at', '<=', now())->update(['status' => 'expired']);
        GiftCard::where('status', 'active')->whereNull('email_claimed_at')->where('send_on', '<=', now(config('shop.timezone'))->toDateString())
            ->orderBy('id')->limit(50)->pluck('id')->each(fn ($id) => $delivery->send($id));

        return self::SUCCESS;
    }
}

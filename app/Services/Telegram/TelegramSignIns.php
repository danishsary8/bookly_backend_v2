<?php

namespace App\Services\Telegram;

use App\Http\Controllers\Api\V1\TelegramWebhookController as Bot;
use App\Models\Customer;
use App\Notifications\AccountSecurityNotice;
use App\Support\SafeNotifier;
use Illuminate\Support\Facades\Cache;

/**
 * After a sign-in through Telegram (the bot's share button or a code the bot sent): the owner gets an email, and
 * for a day the chat can tap "Sign me out everywhere" to end every session of the account.
 */
class TelegramSignIns
{
    /**
     * @param  string|null  $linkCode  the website link being signed in with (cancelled too on "sign me out"); null for codes
     * @return array{string, array<string, mixed>} the bot's message and its keyboard
     */
    public function record(int $chatId, Customer $customer, ?string $linkCode, string $message): array
    {
        Cache::put('telegram-signout:'.$chatId, ['customer_id' => $customer->id, 'code' => $linkCode], now()->addHours(Bot::SIGN_OUT_HOURS));
        $at = now()->timezone('Asia/Phnom_Penh')->format('j M Y, H:i');
        SafeNotifier::toCustomer($customer, new AccountSecurityNotice(
            'New sign-in to Bookly with Telegram',
            "Your Bookly account was signed in with Telegram on {$at} (Phnom Penh time). If this wasn't you, open the Bookly bot and tap “".Bot::SIGN_OUT_BUTTON.'”, or contact us.',
            'telegram_sign_in',
            ['at' => $at, 'button' => Bot::SIGN_OUT_BUTTON],
        ));

        return ["✅ {$message}\n\nSigned in on {$at}. Not you? Tap “".Bot::SIGN_OUT_BUTTON.'” within 24 hours.', [
            'keyboard' => [[['text' => Bot::SIGN_OUT_BUTTON]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ]];
    }
}

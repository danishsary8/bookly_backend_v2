<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Customer\AuthController;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Notifications\AccountSecurityNotice;
use App\Services\Telegram\TelegramBot;
use App\Services\Telegram\TelegramLinks;
use App\Support\PhoneNumber;
use App\Support\SafeNotifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Updates from Telegram for the Bookly bot (POST /telegram/webhook). The conversation is always the same:
 *
 * 1. The website opens t.me/<bot>?start=<code>; Telegram sends us "/start <code>" and we answer with a
 *    "Share my phone number" button.
 * 2. The customer taps it; Telegram sends their contact. Telegram itself proved the number when the account
 *    was made, and `contact.user_id == from.id` shows it is their own (not a forwarded contact card).
 * 3. We sign them in or save the number, and the website (polling POST /auth/telegram/status) carries on.
 *
 * Replies go back in this request's answer (Telegram runs them as a sendMessage call).
 */
class TelegramWebhookController extends Controller
{
    public const SHARE_BUTTON = '📱 Share my phone number';

    /** Sign-in links: sharing the number is the "yes" (security review 2026-10-15: these links can be phished). */
    public const YES_SIGN_IN_BUTTON = '✅ Yes, sign me in (share my number)';

    public const NOT_ME_BUTTON = "❌ No, it wasn't me";

    public const SIGN_OUT_BUTTON = '🚪 Sign me out everywhere';

    /** How long after a Telegram sign-in the chat can still sign that account out everywhere. */
    public const SIGN_OUT_HOURS = 24;

    public const EXPIRED = 'This link has expired. Go back to Bookly and tap the Telegram button again.';

    public const NO_ACCOUNT = 'No Bookly account uses the number of this Telegram account (%s). Create an account, or sign in with your email.';

    public const NOT_CAMBODIAN = 'Bookly only accepts Cambodian (+855) numbers for now, and this Telegram account uses another number.';

    public const DEACTIVATED = 'This account has been deactivated. Please contact support.';

    public const SESSION_ENDED = 'Your Bookly session has ended. Sign in again on the website, then try once more.';

    public function __construct(private readonly TelegramBot $bot, private readonly TelegramLinks $links) {}

    public function __invoke(Request $request): JsonResponse|Response
    {
        if (! TelegramBot::enabled() || ! hash_equals($this->bot->webhookSecret(), (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            abort(404);
        }
        $message = $request->input('message');
        // Only private chats with a person; anything else (groups, channels, edits) needs no answer.
        if (! is_array($message) || ($message['chat']['type'] ?? null) !== 'private' || ! isset($message['chat']['id'], $message['from']['id'])) {
            return response()->noContent();
        }
        $chatId = (int) $message['chat']['id'];

        if (isset($message['contact'])) {
            return $this->sharedContact($chatId, (int) $message['from']['id'], $message['contact']);
        }
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === self::NOT_ME_BUTTON) {
            return $this->notMe($chatId);
        }
        if ($text === self::SIGN_OUT_BUTTON) {
            return $this->signOutEverywhere($chatId);
        }
        if (preg_match('#^/start(?:@\w+)?(?:\s+(\S+))?#', (string) ($message['text'] ?? ''), $start) === 1 && isset($start[1])) {
            return $this->opened($chatId, $start[1]);
        }

        return $this->reply($chatId, "Hi! I'm the Bookly bot. To sign in or confirm your phone number, open me from the Bookly website: tap “Continue with Telegram”.");
    }

    /** "/start <code>": the customer came from the website. */
    private function opened(int $chatId, string $code): JsonResponse
    {
        $link = $this->links->find($code);
        if ($link === null) {
            return $this->reply($chatId, self::EXPIRED, remove: true);
        }
        if ($link['status'] !== 'pending') {
            return $this->reply($chatId, 'This link has already been used. Go back to Bookly to carry on.', remove: true);
        }
        $this->links->rememberChat($chatId, $code);

        if ($link['purpose'] === 'login') {
            $text = "Sign in to Bookly?\n\nA Bookly page asked to sign in with your Telegram number.\n\n"
                .'Only tap “'.self::YES_SIGN_IN_BUTTON.'” if YOU just pressed “Continue with Telegram” on the Bookly website, on your own phone or computer. '
                .'If someone sent you this link (a message, a call, an “order confirmation”), tap “'.self::NOT_ME_BUTTON.'”: they would get into your account.';
            $share = self::YES_SIGN_IN_BUTTON;
        } else {
            $text = "Confirm your phone number for Bookly\n\nTap “".self::SHARE_BUTTON.'” below. It becomes the verified number of your Bookly account. '
                .'Only do this if YOU are adding your number on the Bookly website right now; otherwise tap “'.self::NOT_ME_BUTTON.'”.';
            $share = self::SHARE_BUTTON;
        }

        return $this->reply($chatId, $text, [
            'keyboard' => [[['text' => $share, 'request_contact' => true]], [['text' => self::NOT_ME_BUTTON]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ]);
    }

    /** "No, it wasn't me": the link is cancelled, so whoever opened it on the website gets nothing. */
    private function notMe(int $chatId): JsonResponse
    {
        $code = $this->links->codeForChat($chatId);
        $link = $code !== null ? $this->links->find($code) : null;
        $this->links->forgetChat($chatId);
        if ($link !== null && $link['status'] === 'pending') {
            $this->links->finish($code, ['status' => 'failed', 'message' => 'This was cancelled in Telegram.']);
        }

        return $this->reply($chatId, 'OK, nothing happened: nobody was signed in and no number was shared. Never share your number for a link someone sent you.', remove: true);
    }

    /** "Sign me out everywhere" after a Telegram sign-in: ends every session of that account. */
    private function signOutEverywhere(int $chatId): JsonResponse
    {
        $recent = Cache::pull('telegram-signout:'.$chatId);
        $customer = is_array($recent) ? Customer::find($recent['customer_id']) : null;
        if ($customer === null) {
            return $this->reply($chatId, 'There is no recent Bookly sign-in from this chat to undo. If something looks wrong, reset your password on the website or contact us.', remove: true);
        }
        // The website may not have collected its sign-in yet: it must get nothing.
        if ($this->links->find($recent['code'])) {
            $this->links->finish($recent['code'], ['status' => 'failed', 'message' => 'This sign-in was cancelled in Telegram.']);
        }
        $customer->tokens()->delete();

        return $this->reply($chatId, '✅ Done: every device was signed out of your Bookly account. If you did not sign in yourself, also reset your password if you have one.', remove: true);
    }

    /** The customer tapped "Share my phone number". */
    private function sharedContact(int $chatId, int $fromId, array $contact): JsonResponse
    {
        if ((int) ($contact['user_id'] ?? 0) !== $fromId) {
            return $this->reply($chatId, 'Please use the “'.self::SHARE_BUTTON.'” button: it shares your own number.');
        }
        $code = $this->links->codeForChat($chatId);
        $link = $code !== null ? $this->links->find($code) : null;
        if ($link === null || $link['status'] !== 'pending') {
            return $this->reply($chatId, self::EXPIRED, remove: true);
        }
        $this->links->forgetChat($chatId);

        // Telegram sends the number with or without "+".
        $phone = PhoneNumber::normalize('+'.ltrim((string) ($contact['phone_number'] ?? ''), '+'));
        [$status, $message, $customer] = match (true) {
            $phone === null => ['failed', self::NOT_CAMBODIAN, null],
            $link['purpose'] === 'login' => $this->signIn($code, $phone),
            default => $this->savePhone($code, (int) $link['customer_id'], $phone),
        };
        if ($status === 'failed') {
            $this->links->finish($code, ['status' => 'failed', 'message' => $message]);
        }
        if ($status === 'done' && $link['purpose'] === 'login') {
            return $this->signedIn($chatId, $code, $customer, $message);
        }

        return $this->reply($chatId, $status === 'done' ? "✅ {$message}" : $message, remove: true);
    }

    /** A Telegram sign-in: the owner hears about it, and this chat can undo it for a day. */
    private function signedIn(int $chatId, string $code, Customer $customer, string $message): JsonResponse
    {
        Cache::put('telegram-signout:'.$chatId, ['customer_id' => $customer->id, 'code' => $code], now()->addHours(self::SIGN_OUT_HOURS));
        $at = now()->timezone('Asia/Phnom_Penh')->format('j M Y, H:i');
        SafeNotifier::toCustomer($customer, new AccountSecurityNotice(
            'New sign-in to Bookly with Telegram',
            "Your Bookly account was signed in with Telegram on {$at} (Phnom Penh time). If this wasn't you, open the Bookly bot and tap “".self::SIGN_OUT_BUTTON.'”, or contact us.',
        ));

        return $this->reply($chatId, "✅ {$message}\n\nSigned in on {$at}. Not you? Tap “".self::SIGN_OUT_BUTTON.'” within 24 hours.', [
            'keyboard' => [[['text' => self::SIGN_OUT_BUTTON]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ]);
    }

    /** @return array{string, string, ?Customer} status, message, the account */
    private function signIn(string $code, string $phone): array
    {
        // Closed accounts are soft-deleted, so never found.
        $customer = Customer::where('phone_e164', $phone)->whereNotNull('phone_verified_at')->first();
        if ($customer === null) {
            return ['failed', sprintf(self::NO_ACCOUNT, PhoneNumber::display($phone)), null];
        }
        if (! $customer->is_active) {
            return ['failed', self::DEACTIVATED, null];
        }
        $this->links->finish($code, ['status' => 'done', 'customer_id' => $customer->id]);

        return ['done', "You're signed in. Go back to Bookly.", $customer];
    }

    /** @return array{string, string, ?Customer} status, message, the account */
    private function savePhone(string $code, int $customerId, string $phone): array
    {
        $customer = Customer::find($customerId);
        if ($customer === null || ! $customer->is_active) {
            return ['failed', self::SESSION_ENDED, null];
        }
        if (Customer::withTrashed()->where('phone_e164', $phone)->whereKeyNot($customer->getKey())->exists()) {
            return ['failed', AuthController::PHONE_TAKEN.' Sign in with Telegram instead.', null];
        }
        $wasVerified = $customer->isVerified();
        try {
            $customer->markPhoneVerified($phone);
        } catch (UniqueConstraintViolationException) {
            return ['failed', AuthController::PHONE_TAKEN.' Sign in with Telegram instead.', null];
        }
        $this->links->finish($code, ['status' => 'done']);
        // Not at sign-up: the owner hears about a new way into the account they didn't add.
        if ($wasVerified) {
            SafeNotifier::toCustomer($customer, new AccountSecurityNotice(
                'A phone number was added to your Bookly account',
                PhoneNumber::display($phone).' can now sign in to your Bookly account with Telegram. If this wasn\'t you, reset your password and contact us.',
            ));
        }

        return ['done', PhoneNumber::display($phone).' is now the verified number of your Bookly account. Go back to Bookly.', $customer];
    }

    private function reply(int $chatId, string $text, ?array $keyboard = null, bool $remove = false): JsonResponse
    {
        return response()->json(array_filter([
            'method' => 'sendMessage',
            'chat_id' => $chatId,
            'text' => $text,
            'reply_markup' => $keyboard ?? ($remove ? ['remove_keyboard' => true] : null),
        ]));
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Customer\AuthController;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Telegram\TelegramBot;
use App\Services\Telegram\TelegramLinks;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

        $text = $link['purpose'] === 'login'
            ? "Sign in to Bookly\n\nTap “".self::SHARE_BUTTON."” below. We'll sign you in on the website with the Bookly account that uses this number.\n\nOnly do this if you just tapped “Continue with Telegram” on Bookly yourself."
            : "Confirm your phone number for Bookly\n\nTap “".self::SHARE_BUTTON.'” below. It becomes the verified number of your Bookly account.';

        return $this->reply($chatId, $text, [
            'keyboard' => [[['text' => self::SHARE_BUTTON, 'request_contact' => true]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ]);
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
        [$status, $message] = match (true) {
            $phone === null => ['failed', self::NOT_CAMBODIAN],
            $link['purpose'] === 'login' => $this->signIn($code, $phone),
            default => $this->savePhone($code, (int) $link['customer_id'], $phone),
        };
        if ($status === 'failed') {
            $this->links->finish($code, ['status' => 'failed', 'message' => $message]);
        }

        return $this->reply($chatId, $status === 'done' ? "✅ {$message}" : $message, remove: true);
    }

    /** @return array{string, string} */
    private function signIn(string $code, string $phone): array
    {
        // Closed accounts are soft-deleted, so never found.
        $customer = Customer::where('phone_e164', $phone)->whereNotNull('phone_verified_at')->first();
        if ($customer === null) {
            return ['failed', sprintf(self::NO_ACCOUNT, PhoneNumber::display($phone))];
        }
        if (! $customer->is_active) {
            return ['failed', self::DEACTIVATED];
        }
        $this->links->finish($code, ['status' => 'done', 'customer_id' => $customer->id]);

        return ['done', "You're signed in. Go back to Bookly."];
    }

    /** @return array{string, string} */
    private function savePhone(string $code, int $customerId, string $phone): array
    {
        $customer = Customer::find($customerId);
        if ($customer === null || ! $customer->is_active) {
            return ['failed', self::SESSION_ENDED];
        }
        if (Customer::withTrashed()->where('phone_e164', $phone)->whereKeyNot($customer->getKey())->exists()) {
            return ['failed', AuthController::PHONE_TAKEN.' Sign in with Telegram instead.'];
        }
        try {
            $customer->markPhoneVerified($phone);
        } catch (UniqueConstraintViolationException) {
            return ['failed', AuthController::PHONE_TAKEN.' Sign in with Telegram instead.'];
        }
        $this->links->finish($code, ['status' => 'done']);

        return ['done', PhoneNumber::display($phone).' is now the verified number of your Bookly account. Go back to Bookly.'];
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

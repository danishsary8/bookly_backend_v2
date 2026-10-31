<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\VerificationPurpose;
use App\Exceptions\TelegramBotProblem;
use App\Http\Controllers\Api\V1\Concerns\IssuesTokens;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Rules\Turnstile;
use App\Services\Auth\OtpService;
use App\Services\Telegram\TelegramBot;
use App\Services\Telegram\TelegramSignIns;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

use function Illuminate\Support\defer;

/**
 * Sign in with a phone number and a code the Bookly Telegram bot sends (owner, 2026-10-31).
 *
 * The bot can only message chats that opened it, so this works for numbers whose owner signed in or confirmed the
 * number with Telegram before (customers.telegram_chat_id). Everyone else uses "Continue with Telegram" once.
 *
 * Anyone can type any number, so POST /auth/phone/code answers the same whatever the number (owner's choice): the
 * lookup, the code and the Telegram message all happen after the response, so not even its timing tells whether
 * a number belongs to a Bookly customer.
 */
class PhoneCodeController extends Controller
{
    use IssuesTokens;

    public const TTL_MINUTES = 10;

    /** Per number, whoever asks: stops strangers filling someone's Telegram with codes. */
    public const PER_MINUTE = 1;

    public const PER_HOUR = 5;

    public const SENT = 'If this number has a Bookly account linked to Telegram, we sent a sign-in code there. It works for 10 minutes.';

    public const WRONG = 'That code is wrong or has expired. Check the latest message from the Bookly bot, or ask for a new code.';

    public function __construct(private readonly OtpService $otp) {}

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'turnstile_token' => [new Turnstile($request->ip())],
        ]);
        $phone = PhoneNumber::normalize($data['phone']);
        if ($phone === null) {
            return response()->json(['message' => 'Enter a Cambodian phone number, e.g. 012 345 678.', 'errors' => ['phone' => ['Enter a Cambodian phone number, e.g. 012 345 678.']]], 422);
        }
        if (! TelegramBot::enabled()) {
            return response()->json(['message' => TelegramAuthController::OFF], 503);
        }

        foreach ([['phone-code-1m|', self::PER_MINUTE, 60], ['phone-code-1h|', self::PER_HOUR, 3600]] as [$prefix, $max, $decay]) {
            if (RateLimiter::tooManyAttempts($prefix.$phone, $max)) {
                $wait = RateLimiter::availableIn($prefix.$phone);

                return response()->json([
                    'message' => $decay === 60
                        ? 'A code was just sent to this number. Please wait a minute before asking for another.'
                        : 'Too many codes for this number. Please try again later, or use “Continue with Telegram”.',
                ], 429, ['Retry-After' => $wait]);
            }
        }
        RateLimiter::hit('phone-code-1m|'.$phone, 60);
        RateLimiter::hit('phone-code-1h|'.$phone, 3600);

        defer(fn () => $this->deliver($phone));

        return response()->json(['message' => self::SENT, 'expires_in_minutes' => self::TTL_MINUTES], 202);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'code' => ['required', 'digits:6'],
        ]);
        $phone = PhoneNumber::normalize($data['phone']);
        $customer = $phone === null ? null : $this->reachable($phone);

        if ($customer === null || ! $this->otp->verify($customer, VerificationPurpose::PhoneLogin, $data['code'])) {
            return response()->json(['message' => self::WRONG, 'errors' => ['code' => [self::WRONG]]], 422);
        }

        $chatId = (int) $customer->telegram_chat_id;
        defer(fn () => $this->confirmInTelegram($chatId, $customer));

        return response()->json([
            'customer' => new CustomerResource($customer),
            ...$this->issueToken($customer, ['customer']),
        ]);
    }

    /** An open account with this verified number whose chat the bot can write to. Closed accounts are soft-deleted. */
    private function reachable(string $phone): ?Customer
    {
        return Customer::where('phone_e164', $phone)
            ->whereNotNull('phone_verified_at')
            ->whereNotNull('telegram_chat_id')
            ->where('is_active', true)
            ->first();
    }

    private function deliver(string $phone): void
    {
        $customer = $this->reachable($phone);
        if ($customer === null) {
            return;
        }
        $code = $this->otp->issuePhoneLoginCode($customer, self::TTL_MINUTES);
        $this->tell((int) $customer->telegram_chat_id, [
            'text' => "🔐 Your Bookly sign-in code: <code>{$code}</code>\n\n"
                .'It works for '.self::TTL_MINUTES." minutes. Never share it: Bookly staff will never ask for it.\n\n"
                ."Didn't ask for a code? Ignore this message. Nobody can sign in without it.",
            'parse_mode' => 'HTML',
        ]);
    }

    private function confirmInTelegram(int $chatId, Customer $customer): void
    {
        [$text, $keyboard] = app(TelegramSignIns::class)->record($chatId, $customer, null, "You're signed in to Bookly with a code.");
        $this->tell($chatId, ['text' => $text, 'reply_markup' => $keyboard]);
    }

    private function tell(int $chatId, array $message): void
    {
        try {
            app(TelegramBot::class)->call('sendMessage', ['chat_id' => $chatId, ...$message]);
        } catch (TelegramBotProblem $e) {
            // Usually the customer blocked the bot; "Continue with Telegram" still works for them.
            Log::warning('Telegram message not delivered', ['chat_id' => $chatId, 'reason' => $e->getMessage()]);
        }
    }
}

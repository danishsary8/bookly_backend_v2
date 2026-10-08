<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Exceptions\TelegramBotProblem;
use App\Http\Controllers\Api\V1\Concerns\IssuesTokens;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\Telegram\TelegramBot;
use App\Services\Telegram\TelegramLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phone numbers through the free Bookly bot (owner, 2026-10-08: replaces the paid Telegram Gateway codes).
 *
 * - POST /auth/telegram: "Continue with Telegram" on the sign-in page (guest).
 * - POST /auth/telegram/phone: a signed-in customer confirms or changes their number (after a Telegram
 *   sign-up, a Facebook sign-up, or in Account → Sign-in & security).
 * - POST /auth/telegram/status: the website asks every few seconds how it went.
 *
 * Each start answers a t.me `url` to open and a private `key` that only this browser has.
 */
class TelegramAuthController extends Controller
{
    use IssuesTokens;

    public const OFF = "Telegram isn't available right now. Use your email, Google or Facebook.";

    public function __construct(private readonly TelegramBot $bot, private readonly TelegramLinks $links) {}

    public function login(): JsonResponse
    {
        return $this->start('login');
    }

    public function phone(Request $request): JsonResponse
    {
        return $this->start('phone', $request->user()->getKey());
    }

    public function status(Request $request): JsonResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:100']]);
        $found = $this->links->findByKey($data['key']);
        if ($found === null) {
            return response()->json(['message' => 'This Telegram link has expired. Please try again.'], 404);
        }
        [$code, $link] = $found;

        if ($link['status'] === 'pending') {
            return response()->json(['status' => 'pending', 'expires_at' => $link['expires_at']]);
        }
        if ($link['status'] === 'failed') {
            return response()->json(['status' => 'failed', 'message' => $link['message']]);
        }

        $this->links->forget($code, $data['key']);
        $customer = Customer::find($link['customer_id']);
        if ($customer === null || ! $customer->is_active) {
            return response()->json(['status' => 'failed', 'message' => 'This account is no longer available. Please contact support.']);
        }

        return response()->json([
            'status' => 'done',
            'customer' => new CustomerResource($customer),
            ...($link['purpose'] === 'login' ? $this->issueToken($customer, ['customer']) : []),
        ]);
    }

    private function start(string $purpose, ?int $customerId = null): JsonResponse
    {
        if (! TelegramBot::enabled()) {
            return response()->json(['message' => self::OFF], 503);
        }
        $link = $this->links->create($purpose, $customerId);
        try {
            $url = $this->bot->link($link['code']);
        } catch (TelegramBotProblem $e) {
            // Only the bot's username lookup can fail here (wrong token, Telegram down): ours to fix.
            report($e);

            return response()->json(['message' => self::OFF], 503);
        }

        return response()->json(['url' => $url, 'key' => $link['key'], 'expires_at' => $link['expires_at']], 201);
    }
}

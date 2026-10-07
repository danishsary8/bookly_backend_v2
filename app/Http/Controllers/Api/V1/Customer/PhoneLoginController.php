<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Enums\VerificationPurpose;
use App\Exceptions\TelegramCodeNotSent;
use App\Http\Controllers\Api\V1\Concerns\IssuesTokens;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Rules\CambodianPhone;
use App\Rules\Turnstile;
use App\Services\Auth\OtpService;
use App\Services\Auth\TelegramGateway;
use App\Support\PhoneNumber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Sign in with a phone number and a Telegram code (owner: any account with a verified +855 number).
 *
 * Step 1 answers the same whether or not the number belongs to an account, and counts against the
 * number's code limits either way, so the form can't be used to find out who shops here. Codes only go to
 * numbers an account has already proven. Step 2 checks the code (5 wrong guesses throw it away) and signs
 * in exactly like a password sign-in.
 */
class PhoneLoginController extends Controller
{
    use IssuesTokens;

    public const SENT = 'If this number has a Bookly account, we sent a code to its Telegram.';

    public const WRONG_CODE = 'The code is invalid or has expired.';

    public function __construct(private readonly OtpService $otp) {}

    public function store(Request $request): JsonResponse
    {
        if (! TelegramGateway::enabled()) {
            return response()->json(['message' => "Signing in with a phone number isn't available right now. Use your email or Google/Facebook."], 503);
        }
        $data = $request->validate([
            'phone' => ['required', 'string', new CambodianPhone],
            'turnstile_token' => [new Turnstile($request->ip())],
        ]);
        $phone = PhoneNumber::normalize($data['phone']);
        $this->otp->spendPhoneAllowance($phone);

        $customer = $this->account($phone);
        if ($customer !== null) {
            try {
                $this->otp->issueLoginCode($customer);
            } catch (TelegramCodeNotSent $e) {
                // Same answer as an unknown number; the customer can still use their other ways in.
                Log::warning('Phone sign-in code not sent', ['customer_id' => $customer->id, 'reason' => $e->getMessage()]);
            }
        }

        return response()->json(['message' => self::SENT, 'phone' => PhoneNumber::display($phone)]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', new CambodianPhone],
            'code' => ['required', 'digits:6'],
        ]);
        $customer = $this->account(PhoneNumber::normalize($data['phone']));

        if ($customer === null || ! $this->otp->verify($customer, VerificationPurpose::PhoneLogin, $data['code'])) {
            return response()->json(['message' => self::WRONG_CODE, 'errors' => ['code' => [self::WRONG_CODE]]], 422);
        }

        return response()->json([
            'customer' => new CustomerResource($customer),
            ...$this->issueToken($customer, ['customer']),
        ]);
    }

    /** The active account that proved this number (closed accounts are soft-deleted, so never found). */
    private function account(string $phoneE164): ?Customer
    {
        return Customer::where('phone_e164', $phoneE164)->whereNotNull('phone_verified_at')->where('is_active', true)->first();
    }
}

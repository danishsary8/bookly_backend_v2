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
use Illuminate\Validation\ValidationException;

/**
 * Sign in with a phone number and a Telegram code (owner: any account with a verified +855 number).
 *
 * Step 1 says plainly when a number has no account or the code can't be sent, so nobody waits for a code that
 * never comes (sign-up already says when a number is taken, so there is nothing to hide). Codes only go to
 * numbers an account has proven. Step 2 checks the code (5 wrong guesses throw it away) and signs in exactly
 * like a password sign-in.
 */
class PhoneLoginController extends Controller
{
    use IssuesTokens;

    public const NO_ACCOUNT = "We couldn't find a Bookly account with this verified number. Check the number, or sign in with your email.";

    public const NOT_SENT_NUMBER = "We couldn't send a Telegram code to this number. Check it has Telegram, or sign in with your email.";

    public const NOT_SENT_OURS = "We couldn't send the code just now. Please try again in a moment, or sign in with your email.";

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

        $customer = Customer::where('phone_e164', $phone)->whereNotNull('phone_verified_at')->first(); // closed accounts are soft-deleted
        if ($customer === null) {
            throw ValidationException::withMessages(['phone' => self::NO_ACCOUNT]);
        }
        if (! $customer->is_active) {
            return response()->json(['message' => 'This account has been deactivated. Please contact support.'], 403);
        }

        try {
            $this->otp->issueLoginCode($customer);
        } catch (TelegramCodeNotSent $e) {
            // The gateway's own log line (and Sentry) say why; the customer is told whose problem it is.
            throw ValidationException::withMessages(['phone' => $e->numberProblem ? self::NOT_SENT_NUMBER : self::NOT_SENT_OURS]);
        }

        return response()->json(['message' => 'We sent a 6-digit code to the Telegram of this number.', 'phone' => PhoneNumber::display($phone)]);
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

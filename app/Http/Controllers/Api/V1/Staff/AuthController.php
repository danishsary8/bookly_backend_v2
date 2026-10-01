<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\VerificationPurpose;
use App\Http\Controllers\Api\V1\Concerns\IssuesTokens;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffUserResource;
use App\Models\StaffUser;
use App\Services\Auth\OtpService;
use App\Services\Auth\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    use IssuesTokens;

    private const CHALLENGE_TTL_MINUTES = 5;

    public function __construct(
        private readonly TotpService $totp,
        private readonly OtpService $otp,
    ) {}

    /** Step 1: password. Staff with 2FA get a short-lived challenge instead of a token. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $staff = StaffUser::where('email', strtolower($data['email']))->first();

        if ($staff === null || ! Hash::check($data['password'], $staff->password_hash)) {
            return response()->json(['message' => 'Invalid email or password.'], 401);
        }

        if ($staff->two_factor_enabled) {
            $challenge = Str::random(64);
            Cache::put($this->challengeKey($challenge), $staff->id, now()->addMinutes(self::CHALLENGE_TTL_MINUTES));

            return response()->json([
                'two_factor_required' => true,
                'challenge_token' => $challenge,
                'expires_in' => self::CHALLENGE_TTL_MINUTES * 60,
            ]);
        }

        // No 2FA yet: the token only works for 2FA setup until 2FA is confirmed (see staff.2fa middleware).
        return response()->json([
            'two_factor_required' => false,
            'two_factor_setup_required' => true,
            'staff' => new StaffUserResource($staff),
            ...$this->issueToken($staff, $staff->tokenAbilities()),
        ]);
    }

    /** Step 2: authenticator-app code. */
    public function challenge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);

        $staffId = Cache::get($this->challengeKey($data['challenge_token']));
        $staff = $staffId ? StaffUser::find($staffId) : null;

        if ($staff === null || ! $staff->two_factor_enabled) {
            return response()->json(['message' => 'This login attempt has expired. Please log in again.'], 401);
        }

        if (! $this->verifyFreshCode($staff, $data['code'])) {
            return response()->json(['message' => 'Invalid authentication code.'], 422);
        }

        Cache::forget($this->challengeKey($data['challenge_token']));

        return response()->json([
            'staff' => new StaffUserResource($staff),
            ...$this->issueToken($staff, $staff->tokenAbilities()),
        ]);
    }

    public function setupTwoFactor(Request $request): JsonResponse
    {
        $staff = $request->user();

        if ($staff->two_factor_enabled) {
            return response()->json(['message' => 'Two-factor authentication is already enabled.'], 409);
        }

        $secret = $this->totp->generateSecret();
        $staff->forceFill(['two_factor_secret' => $secret])->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => $this->totp->provisioningUri($secret, $staff->email, config('app.name')),
            'message' => 'Scan the QR code (otpauth_uri) in your authenticator app, then confirm with a code.',
        ]);
    }

    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $staff = $request->user();

        if ($staff->two_factor_enabled) {
            return response()->json(['message' => 'Two-factor authentication is already enabled.'], 409);
        }

        if ($staff->two_factor_secret === null) {
            return response()->json(['message' => 'Start two-factor setup first.'], 422);
        }

        if (! $this->verifyFreshCode($staff, $data['code'])) {
            return response()->json(['message' => 'Invalid authentication code.'], 422);
        }

        $staff->forceFill(['two_factor_enabled' => true])->save();

        return response()->json(['message' => 'Two-factor authentication enabled.', 'staff' => new StaffUserResource($staff)]);
    }

    public function me(Request $request): StaffUserResource
    {
        return new StaffUserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $staff = StaffUser::where('email', strtolower($data['email']))->first();

        if ($staff !== null) {
            $this->otp->issue($staff, VerificationPurpose::PasswordReset);
        }

        return response()->json(['message' => 'If that email is registered, a reset code has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $staff = StaffUser::where('email', strtolower($data['email']))->first();

        if ($staff === null || ! $this->otp->verify($staff, VerificationPurpose::PasswordReset, $data['code'])) {
            return response()->json(['message' => 'The code is invalid or has expired.'], 422);
        }

        // 2FA stays enabled: a reset email alone is not enough to get into a staff account.
        $staff->forceFill(['password_hash' => $data['password']])->save();
        $staff->tokens()->delete();

        return response()->json(['message' => 'Password reset. Please log in again.']);
    }

    /** Rejects a code that was already used, so a code seen over someone's shoulder cannot be replayed. */
    private function verifyFreshCode(StaffUser $staff, string $code): bool
    {
        if (! $this->totp->verify($staff->two_factor_secret, $code)) {
            return false;
        }

        return Cache::add("staff-totp-used:{$staff->id}:{$code}", true, now()->addSeconds(120));
    }

    private function challengeKey(string $challenge): string
    {
        return 'staff-2fa-challenge:'.hash('sha256', $challenge);
    }
}

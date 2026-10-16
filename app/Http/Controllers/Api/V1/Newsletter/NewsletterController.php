<?php

namespace App\Http\Controllers\Api\V1\Newsletter;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use App\Notifications\NewsletterConfirmNotification;
use App\Rules\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

class NewsletterController extends Controller
{
    /**
     * Public newsletter sign-up with double opt-in.
     * Always returns 202 to avoid email enumeration.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:190'],
            'locale' => ['nullable', 'string', 'in:en,km'],
            'turnstile_token' => [new Turnstile($request->ip())],
        ]);

        $email = Str::lower(trim($data['email']));
        $locale = $data['locale'] ?? app()->getLocale() ?: 'en';
        if (! in_array($locale, ['en', 'km'], true)) {
            $locale = 'en';
        }

        $subscriber = NewsletterSubscriber::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($subscriber && $subscriber->status === 'confirmed') {
            return response()->json(['message' => __('messages.newsletter_check_inbox')], 202);
        }

        // At most one confirm email per address every 10 minutes (Cache::add is atomic).
        if (Cache::add('newsletter:confirm:throttle:'.hash('sha256', $email), true, now()->addMinutes(10))) {
            $rawConfirmToken = NewsletterSubscriber::generateRawToken();

            if ($subscriber) {
                $subscriber->update([
                    'locale' => $locale,
                    'status' => 'pending',
                    'confirm_token_hash' => NewsletterSubscriber::hashToken($rawConfirmToken),
                ]);
            } else {
                $subscriber = NewsletterSubscriber::create([
                    'email' => $email,
                    'locale' => $locale,
                    'status' => 'pending',
                    'confirm_token_hash' => NewsletterSubscriber::hashToken($rawConfirmToken),
                    ...NewsletterSubscriber::unsubscribeFields(),
                ]);
            }

            try {
                Notification::route('mail', $email)
                    ->notify((new NewsletterConfirmNotification($rawConfirmToken))->locale($locale));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json(['message' => __('messages.newsletter_check_inbox')], 202);
    }

    /**
     * Confirm newsletter subscription via token.
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $tokenHash = NewsletterSubscriber::hashToken(trim($data['token']));
        $subscriber = NewsletterSubscriber::where('confirm_token_hash', $tokenHash)
            ->where('updated_at', '>', now()->subDays(NewsletterSubscriber::CONFIRM_DAYS))
            ->first();

        if (! $subscriber) {
            return response()->json(['message' => __('messages.newsletter_confirm_invalid')], 422);
        }

        $subscriber->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'confirm_token_hash' => null,
        ]);

        return response()->json(['message' => __('messages.newsletter_confirmed')]);
    }

    /**
     * Unsubscribe from newsletter via token (web form).
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        $subscriber = NewsletterSubscriber::findByUnsubscribeToken($data['token']);

        if (! $subscriber) {
            return response()->json(['message' => __('messages.newsletter_unsubscribe_invalid')], 422);
        }

        $subscriber->update([
            'status' => 'unsubscribed',
            'unsubscribed_at' => now(),
        ]);

        return response()->json(['message' => __('messages.newsletter_unsubscribed')]);
    }

    /**
     * RFC 8058 one-click unsubscribe for mail user agents.
     */
    public function oneClickUnsubscribe(Request $request): JsonResponse
    {
        $token = (string) ($request->query('token') ?: $request->input('token'));

        if ($token === '') {
            return response()->json(['message' => __('messages.newsletter_token_missing')], 400);
        }

        $subscriber = NewsletterSubscriber::findByUnsubscribeToken($token);

        if (! $subscriber) {
            return response()->json(['message' => __('messages.newsletter_unsubscribe_invalid')], 422);
        }

        $subscriber->update([
            'status' => 'unsubscribed',
            'unsubscribed_at' => now(),
        ]);

        return response()->json(['message' => __('messages.newsletter_unsubscribed')]);
    }
}

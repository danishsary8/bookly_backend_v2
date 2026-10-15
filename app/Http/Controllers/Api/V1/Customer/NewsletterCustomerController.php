<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\NewsletterSubscriber;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class NewsletterCustomerController extends Controller
{
    /**
     * Get customer's current newsletter subscription status.
     */
    public function show(Request $request): JsonResponse
    {
        $customer = $request->user();
        $email = Str::lower(trim((string) $customer->email));

        $subscriber = null;
        if ($email !== '') {
            $subscriber = NewsletterSubscriber::where('customer_id', $customer->id)
                ->orWhereRaw('LOWER(email) = ?', [$email])
                ->first();
        }

        if (! $subscriber) {
            return response()->json([
                'subscribed' => false,
                'status' => 'none',
                'locale' => $customer->locale ?? 'en',
            ]);
        }

        return response()->json([
            'subscribed' => $subscriber->status === 'confirmed',
            'status' => $subscriber->status,
            'locale' => $subscriber->locale,
        ]);
    }

    /**
     * Subscribe or unsubscribe directly for verified customer.
     */
    public function update(Request $request): JsonResponse
    {
        $customer = $request->user();

        if (blank($customer->email) || $customer->email_verified_at === null) {
            return response()->json([
                'message' => 'Please verify your email address before managing newsletter preferences.',
            ], 403);
        }

        $data = $request->validate([
            'subscribed' => ['required', 'boolean'],
            'locale' => ['nullable', 'string', 'in:en,km'],
        ]);

        $email = Str::lower(trim($customer->email));
        $locale = $data['locale'] ?? $customer->locale ?? 'en';
        if (! in_array($locale, ['en', 'km'], true)) {
            $locale = 'en';
        }

        $subscriber = NewsletterSubscriber::where('customer_id', $customer->id)
            ->orWhereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($data['subscribed']) {
            if ($subscriber) {
                $subscriber->update([
                    'customer_id' => $customer->id,
                    'status' => 'confirmed',
                    'confirmed_at' => $subscriber->confirmed_at ?? now(),
                    'unsubscribed_at' => null,
                    'locale' => $locale,
                ]);
            } else {
                $rawUnsubToken = hash_hmac('sha256', 'newsletter:unsub:'.$email, config('app.key'));
                $subscriber = NewsletterSubscriber::create([
                    'customer_id' => $customer->id,
                    'email' => $email,
                    'locale' => $locale,
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'unsubscribe_token_hash' => NewsletterSubscriber::hashToken($rawUnsubToken),
                ]);
            }

            return response()->json([
                'message' => 'You are subscribed to the newsletter.',
                'subscribed' => true,
                'status' => 'confirmed',
                'locale' => $subscriber->locale,
            ]);
        }

        // Unsubscribe
        if ($subscriber) {
            $subscriber->update([
                'status' => 'unsubscribed',
                'unsubscribed_at' => now(),
            ]);
        }

        return response()->json([
            'message' => 'You have unsubscribed from the newsletter.',
            'subscribed' => false,
            'status' => 'unsubscribed',
            'locale' => $subscriber?->locale ?? $locale,
        ]);
    }
}

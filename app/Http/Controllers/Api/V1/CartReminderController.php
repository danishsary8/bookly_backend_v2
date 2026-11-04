<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Marketing\CartReminders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The "stop these emails" link in a cart reminder (no sign-in needed), and the mail app's one-click version. */
class CartReminderController extends Controller
{
    public function stop(Request $request, CartReminders $reminders): JsonResponse
    {
        $data = $request->validate([
            'customer' => ['required', 'integer'],
            'token' => ['required', 'string', 'max:100'],
        ]);

        return $this->answer($reminders->stop((int) $data['customer'], $data['token']));
    }

    /** RFC 8058: the mail app POSTs to the List-Unsubscribe URL, with the details in the query string. */
    public function oneClick(Request $request, CartReminders $reminders): JsonResponse
    {
        return $this->answer($reminders->stop((int) $request->query('customer'), (string) $request->query('token')));
    }

    private function answer(bool $stopped): JsonResponse
    {
        return $stopped
            ? response()->json(['message' => __('messages.cart_reminders_stopped')])
            : response()->json(['message' => __('messages.cart_reminders_link_invalid')], 422);
    }
}

<?php

namespace App\Http\Resources;

use App\Models\StaffUser;
use App\Services\Auth\OtpService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'phone_number' => $this->phone_e164,
            'phone_verified' => $this->hasVerifiedPhone(),
            'email_verified' => $this->email_verified_at !== null,
            'verified' => $this->isVerified(),
            'has_password' => $this->password_hash !== null,
            'locale' => $this->locale ?? 'en',
            'telegram_chat_id' => $this->telegram_chat_id,
            'telegram_connected' => $this->telegram_chat_id !== null,
            'telegram_order_updates' => (bool) $this->telegram_order_updates,
            'connected' => ['google' => $this->google_id !== null, 'facebook' => $this->facebook_id !== null],
            'sign_in_methods' => $this->signInMethods(),
            // To the customer only, not to staff: an address waiting for its code (Telegram sign-up, change email).
            'pending_email' => $this->when(! $request->user() instanceof StaffUser, fn () => app(OtpService::class)->pendingEmail($this->resource)),
            'created_at' => $this->created_at,
        ];
    }
}

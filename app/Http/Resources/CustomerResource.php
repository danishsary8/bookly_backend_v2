<?php

namespace App\Http\Resources;

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
            'connected' => ['google' => $this->google_id !== null, 'facebook' => $this->facebook_id !== null],
            'sign_in_methods' => $this->signInMethods(),
            'created_at' => $this->created_at,
        ];
    }
}

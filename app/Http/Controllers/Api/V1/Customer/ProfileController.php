<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function show(Request $request): CustomerResource
    {
        return new CustomerResource($request->user());
    }

    public function update(Request $request): CustomerResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        $request->user()->update($data);

        return new CustomerResource($request->user());
    }

    public function changePassword(Request $request): JsonResponse
    {
        $customer = $request->user();
        $data = $request->validate([
            'current_password' => [$customer->password_hash === null ? 'nullable' : 'required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        if ($customer->password_hash !== null && ! Hash::check($data['current_password'] ?? '', $customer->password_hash)) {
            return response()->json(['message' => 'Current password is incorrect.'], 422);
        }

        $customer->update(['password_hash' => $data['password']]);
        $customer->tokens()->where('id', '!=', $customer->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password changed. Other sessions were signed out.']);
    }
}

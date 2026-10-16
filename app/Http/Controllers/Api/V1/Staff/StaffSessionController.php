<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\StaffLoginHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffSessionController extends Controller
{
    /** List active sessions (Sanctum personal access tokens) for the current staff user. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;

        $tokens = $user->tokens()
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($token) => [
                'id' => $token->id,
                'device' => $token->name,
                'ip_address' => $token->ip_address,
                'last_used_at' => $token->last_used_at?->toISOString(),
                'created_at' => $token->created_at?->toISOString(),
                'is_current' => $token->id === $currentTokenId,
            ]);

        return response()->json(['data' => $tokens]);
    }

    /** Revoke a specific session token belonging to the current staff user. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $token = $user->tokens()->whereKey($id)->first();

        if ($token) {
            $token->delete();
        }

        return response()->json(['message' => 'Session revoked.']);
    }

    /** Revoke all other sessions except the current one. */
    public function revokeOthers(Request $request): JsonResponse
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;

        if ($currentTokenId) {
            $user->tokens()->whereKeyNot($currentTokenId)->delete();
        }

        return response()->json(['message' => 'Other sessions revoked.']);
    }

    /** Sign out everywhere by revoking all tokens including current. */
    public function revokeAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'All sessions revoked.']);
    }

    /** View login history for the authenticated staff user. */
    public function loginHistory(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $history = StaffLoginHistory::where('staff_user_id', $request->user()->id)
            ->latest('id')
            ->paginate($perPage);

        return response()->json($history);
    }
}

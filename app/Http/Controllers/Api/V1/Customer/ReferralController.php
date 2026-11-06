<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Services\Credit\CreditWallet;
use App\Services\Credit\Referrals;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReferralController extends Controller
{
    public function show(Request $request, Referrals $referrals): JsonResponse
    {
        abort_unless($request->user()->email_verified_at !== null, 403);
        $profile = $referrals->profile($request->user(), $request->header('X-Bookly-Device'));
        $history = DB::table('referrals')->where('sharer_customer_id', $request->user()->id)->latest('id')->paginate(20, ['id', 'status', 'reward_cents', 'created_at', 'rewarded_at']);

        return response()->json(['data' => ['code' => $profile->code, 'friend_discount_usd' => (string) config('shop.referral_friend_discount'), 'reward_usd' => (string) config('shop.referral_sharer_credit'), 'balance_usd' => Money::format(app(CreditWallet::class)->balance($request->user())), 'history' => $history->items(), 'current_page' => $history->currentPage(), 'last_page' => $history->lastPage()]])->header('Cache-Control', 'no-store');
    }
}

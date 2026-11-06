<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Services\Credit\CreditWallet;
use App\Services\Credit\GiftCode;
use App\Services\Credit\GiftPayment;
use App\Services\Credit\GiftPurchase;
use App\Services\Credit\GiftRedemption;
use App\Support\Money;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GiftCardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cards = GiftCard::where('buyer_customer_id', $request->user()->id)->latest('id')->paginate(20);

        return response()->json(['data' => $cards->map(fn ($card) => $this->payload($card)), 'balance_usd' => Money::format(app(CreditWallet::class)->balance($request->user())), 'current_page' => $cards->currentPage(), 'last_page' => $cards->lastPage()])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, GiftPurchase $purchase): JsonResponse
    {
        $data = $request->validate([
            'amount_usd' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'recipient_name' => ['required', 'string', 'max:120'], 'recipient_email' => ['required', 'email', 'max:190'],
            'message' => ['nullable', 'string', 'max:300'], 'send_on' => ['required', 'date_format:Y-m-d'],
        ]);
        try {
            $id = $purchase->start($request->user(), Money::toCents($data['amount_usd']), $data['recipient_name'], $data['recipient_email'], $data['message'] ?? null, $data['send_on'], (string) $request->header('Idempotency-Key'));
        } catch (DomainException) {
            throw ValidationException::withMessages(['gift_card' => __('credit.purchase_unavailable')]);
        }

        return response()->json(['data' => $this->payload(GiftCard::findOrFail($id))], 201)->header('Cache-Control', 'no-store');
    }

    public function payment(Request $request, int $id, GiftPayment $payment): JsonResponse
    {
        $payment->refresh($request->user(), $id);
        $card = GiftCard::where('buyer_customer_id', $request->user()->id)->findOrFail($id);
        $qr = DB::table('gift_card_payments')->where('gift_card_id', $id)->first();

        return response()->json(['data' => $this->payload($card), 'payment' => $card->status === 'pending_payment' && $qr ? ['qr_payload' => $qr->qr_payload, 'expires_at' => $qr->expires_at, 'mode' => config('payments.khqr.mode')] : null])->header('Cache-Control', 'no-store');
    }

    public function reveal(Request $request, int $id, GiftCode $codes): JsonResponse
    {
        $code = DB::transaction(function () use ($request, $id, $codes) {
            $card = GiftCard::where('buyer_customer_id', $request->user()->id)->lockForUpdate()->findOrFail($id);
            if ($card->status !== 'active' || $card->code_revealed_at !== null || now()->greaterThanOrEqualTo($card->expires_at)) {
                throw ValidationException::withMessages(['gift_card' => __('credit.code_unavailable')]);
            }
            $card->update(['code_revealed_at' => now()]);

            return $codes->forId($card->id);
        });

        return response()->json(['data' => ['code' => $code]])->header('Cache-Control', 'no-store');
    }

    public function redeem(Request $request, GiftRedemption $redemption): JsonResponse
    {
        // Always use the same response for wrong, unavailable and locked codes.
        $code = $request->input('gift_code');
        try {
            $balance = $redemption->redeem($request->user(), is_string($code) && strlen($code) <= 64 ? $code : '');
        } catch (DomainException) {
            throw ValidationException::withMessages(['gift_code' => __('credit.redeem_unavailable')]);
        }

        return response()->json(['data' => ['balance_usd' => Money::format($balance)]])->header('Cache-Control', 'no-store');
    }

    public function payload(GiftCard $card): array
    {
        return ['id' => $card->id, 'amount_usd' => Money::format((int) $card->amount_cents), 'recipient_name' => $card->recipient_name,
            'recipient_email' => $card->recipient_email, 'message' => $card->message, 'send_on' => $card->send_on,
            'status' => $card->status, 'last_four' => $card->last_four, 'expires_at' => $card->expires_at,
            'can_reveal' => $card->status === 'active' && $card->code_revealed_at === null,
            'email_sent_at' => $card->email_sent_at, 'email_failed_at' => $card->email_failed_at];
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Staff\Promotions;

use App\Http\Controllers\Controller;
use App\Models\GiftCard;
use App\Services\Admin\AuditLogger;
use App\Services\Credit\GiftDelivery;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GiftCardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['last_four' => ['nullable', 'string', 'size:4'], 'buyer_id' => ['nullable', 'integer'], 'status' => ['nullable', Rule::in(['pending_payment', 'active', 'redeemed', 'cancelled', 'expired'])]]);
        $query = GiftCard::query();
        foreach (['last_four', 'status'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $field === 'last_four' ? strtoupper($data[$field]) : $data[$field]);
            }
        }
        if (! empty($data['buyer_id'])) {
            $query->where('buyer_customer_id', $data['buyer_id']);
        }
        $cards = $query->latest('id')->paginate(30);
        $totals = GiftCard::selectRaw("COALESCE(SUM(amount_cents) FILTER (WHERE paid_at IS NOT NULL), 0) AS sold, COALESCE((SELECT SUM(balance_cents) FROM credit_wallets), 0) AS wallets, COALESCE(SUM(amount_cents) FILTER (WHERE status = 'redeemed'), 0) AS redeemed, COALESCE(SUM(amount_cents) FILTER (WHERE status = 'active'), 0) AS outstanding, COALESCE(SUM(amount_cents) FILTER (WHERE paid_at IS NOT NULL AND status IN ('cancelled','expired')), 0) AS review")->first();

        return response()->json(['data' => $cards->items(), 'current_page' => $cards->currentPage(), 'last_page' => $cards->lastPage(), 'totals' => ['sold_usd' => Money::format((int) $totals->sold), 'redeemed_usd' => Money::format((int) $totals->redeemed), 'outstanding_usd' => Money::format((int) $totals->outstanding + (int) $totals->wallets), 'refund_review_usd' => Money::format((int) $totals->review)]])->header('Cache-Control', 'no-store');
    }

    public function show(int $id): JsonResponse
    {
        $card = GiftCard::findOrFail($id);

        return response()->json(['data' => $card, 'payment' => DB::table('gift_card_payments')->where('gift_card_id', $id)->first(['status', 'paid_at', 'expires_at'])])->header('Cache-Control', 'no-store');
    }

    public function cancel(Request $request, int $id, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $id, $audit) {
            $card = GiftCard::lockForUpdate()->findOrFail($id);
            if (! in_array($card->status, ['pending_payment', 'active'], true)) {
                throw ValidationException::withMessages(['gift_card' => __('credit.code_unavailable')]);
            }
            $before = $card->status;
            $card->update(['status' => 'cancelled']);
            $audit->custom($request->user(), 'cancelled', $card, ['status' => $before], ['status' => 'cancelled', 'manual_refund_required' => $card->paid_at !== null]);
        });

        return $this->show($id);
    }

    public function resend(Request $request, int $id, AuditLogger $audit, GiftDelivery $delivery): JsonResponse
    {
        DB::transaction(function () use ($request, $id, $audit) {
            $card = GiftCard::lockForUpdate()->findOrFail($id);
            if ($card->status !== 'active' || now()->greaterThanOrEqualTo($card->expires_at) || ($card->email_claimed_at && now()->subHour()->lessThan($card->email_claimed_at))) {
                throw ValidationException::withMessages(['gift_card' => __('credit.code_unavailable')]);
            }
            $card->update(['email_claimed_at' => null]);
            $audit->custom($request->user(), 'resend_requested', $card, null, ['requested_at' => now()->toIso8601String()]);
        });
        $delivery->send($id);

        return $this->show($id);
    }
}

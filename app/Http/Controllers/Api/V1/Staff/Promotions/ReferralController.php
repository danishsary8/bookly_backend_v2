<?php

namespace App\Http\Controllers\Api\V1\Staff\Promotions;

use App\Http\Controllers\Controller;
use App\Models\Referral;
use App\Services\Admin\AuditLogger;
use App\Services\Credit\Referrals;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ReferralController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'qualified', 'rewarded', 'void'])]]);
        $rows = Referral::when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->latest('id')->paginate(30);
        $stats = Referral::selectRaw('COUNT(*) AS total, COUNT(*) FILTER (WHERE status = \'rewarded\') AS rewarded, COALESCE(SUM(reward_cents), 0) AS credited, COALESCE(SUM(unrecovered_cents), 0) AS unrecovered')->first();

        return response()->json(['data' => $rows->items(), 'current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'stats' => ['total' => (int) $stats->total, 'rewarded' => (int) $stats->rewarded, 'credited_usd' => Money::format((int) $stats->credited), 'unrecovered_usd' => Money::format((int) $stats->unrecovered)]])->header('Cache-Control', 'no-store');
    }

    public function void(Request $request, int $id, Referrals $referrals, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $id, $referrals, $audit) {
            $row = Referral::lockForUpdate()->findOrFail($id);
            $before = $row->status;
            $referrals->void($id);
            if ($before !== 'void') {
                $audit->custom($request->user(), 'voided', $row, ['status' => $before], ['status' => 'void']);
            }
        });

        return response()->json(['data' => Referral::findOrFail($id)])->header('Cache-Control', 'no-store');
    }
}

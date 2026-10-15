<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreCreditResource;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StoreCreditController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $credits = Coupon::query()
            ->where('user_id', $request->user()->id)
            ->where('is_store_credit', true)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses');
            })
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->get();

        return StoreCreditResource::collection($credits);
    }
}

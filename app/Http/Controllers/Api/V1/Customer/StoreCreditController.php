<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\StoreCreditResource;
use App\Models\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StoreCreditController extends Controller
{
    /** The customer's store credits (refunds paid as a coupon), newest first, used and expired ones included. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return StoreCreditResource::collection(
            Coupon::where('customer_id', $request->user()->id)->latest('id')->get()
        );
    }
}

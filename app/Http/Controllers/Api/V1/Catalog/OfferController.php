<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Services\Offers\LiveOffer;
use App\Services\Offers\OfferService;
use Illuminate\Http\JsonResponse;

/** Public: one live automatic offer, for its page in the shop (GET /offers/{id}); its books come from GET /books?offer_id=. */
class OfferController extends Controller
{
    public function show(int $offer, OfferService $offers): JsonResponse
    {
        $live = collect($offers->live())->first(fn (LiveOffer $o) => $o->id === $offer && ! $o->wholeSeries);
        abort_if($live === null, 404, 'This offer has ended.');

        return response()->json(['data' => [
            'id' => $live->id,
            'name' => $live->name,
            'type' => $live->type,
            'min_quantity' => $live->minQuantity,
            'percent_off' => $live->percentOff,
            'amount_off' => $live->amountOff,
            'bundle_price' => $live->bundlePrice,
            'ends_at' => $live->endsAtIso,
            'target_label' => $live->targetLabel,
            'free_delivery' => $live->freeDelivery,
        ]]);
    }
}

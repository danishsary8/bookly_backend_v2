<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use App\Services\Offers\LiveOffer;
use App\Services\Offers\OfferService;
use Illuminate\Http\JsonResponse;

/**
 * The shop's promotion pop-up (owner 2026-11-12): the one live promotion (the newest start wins), and two counters
 * the shop calls when the pop-up is shown and when its button is used. Nothing here needs a login.
 */
class PromotionController extends Controller
{
    public function current(OfferService $offers): JsonResponse
    {
        $promotion = Promotion::live()->orderByRaw('starts_at DESC NULLS LAST')->orderByDesc('id')->first();
        if ($promotion === null) {
            return response()->json(['data' => null]);
        }

        // The linked offer's numbers only while that offer is live itself, so the pop-up never promises an ended deal.
        $offer = $promotion->offer_id === null ? null
            : collect($offers->live())->first(fn (LiveOffer $live) => $live->id === $promotion->offer_id);

        return response()->json(['data' => [
            'id' => $promotion->id,
            'title_en' => $promotion->title_en,
            'title_km' => $promotion->title_km,
            'body_en' => $promotion->body_en,
            'body_km' => $promotion->body_km,
            'image_url' => $promotion->image_url,
            'cta_label_en' => $promotion->cta_label_en,
            'cta_label_km' => $promotion->cta_label_km,
            'cta_url' => $promotion->cta_url,
            'starts_at' => $promotion->starts_at?->toIso8601ZuluString(),
            'ends_at' => $promotion->ends_at?->toIso8601ZuluString(),
            'offer' => $offer === null ? null : [
                'id' => $offer->id,
                'percent_off' => $offer->percentOff,
                'min_quantity' => $offer->minQuantity,
                'whole_series' => $offer->wholeSeries,
                'free_delivery' => $offer->freeDelivery,
                'target_label' => $offer->targetLabel,
            ],
        ]]);
    }

    public function view(Promotion $promotion): JsonResponse
    {
        $promotion->increment('views');

        return response()->json(null, 204);
    }

    public function click(Promotion $promotion): JsonResponse
    {
        $promotion->increment('clicks');

        return response()->json(null, 204);
    }
}

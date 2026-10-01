<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\SeriesResource;
use App\Models\Series;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SeriesController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return SeriesResource::collection(
            Series::query()
                ->withCount(['books' => fn ($q) => $q->visible()])
                ->orderBy('name')
                ->paginate($data['per_page'] ?? 20)
                ->withQueryString()
        );
    }

    /** A series with its visible books in reading order. */
    public function show(Series $series): SeriesResource
    {
        return new SeriesResource($series->load(['books' => fn ($q) => $q
            ->visible()
            ->with(['authors', 'activeVariants'])
            ->withMin('activeVariants as min_price', 'price_usd')
            ->withAvg('approvedReviews as rating_avg', 'rating')
            ->withCount('approvedReviews as review_count'),
        ]));
    }
}

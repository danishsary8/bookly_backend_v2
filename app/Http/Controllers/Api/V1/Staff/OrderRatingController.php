<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderRatingResource;
use App\Models\OrderRating;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\Request;

class OrderRatingController extends Controller
{
    public function index(Request $request)
    {
        $input = $request->validate(['stars' => ['nullable', 'integer', 'between:1,5'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);

        return OrderRatingResource::collection(OrderRating::with('order')->when($input['stars'] ?? null, fn ($q, $stars) => $q->where('stars', $stars))->latest()->orderByDesc('id')->paginate($input['per_page'] ?? 25));
    }

    public function hide(Request $request, OrderRating $rating, AuditLogger $audit)
    {
        $before = ['is_comment_hidden' => $rating->is_comment_hidden];
        $rating->forceFill(['is_comment_hidden' => true])->save();
        $audit->custom($request->user(), 'updated', $rating, $before, ['is_comment_hidden' => true]);

        return new OrderRatingResource($rating->load('order'));
    }
}

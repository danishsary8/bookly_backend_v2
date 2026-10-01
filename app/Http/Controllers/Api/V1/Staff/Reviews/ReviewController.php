<?php

namespace App\Http\Controllers\Api\V1\Staff\Reviews;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffReviewResource;
use App\Models\Review;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Reviews are published right away; staff can hide abusive ones and show them again. */
class ReviewController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'visible' => ['nullable', 'boolean'],
            'book_id' => ['nullable', 'integer'],
            'max_rating' => ['nullable', 'integer', 'between:1,5'],
            'q' => ['nullable', 'string', 'max:200'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return StaffReviewResource::collection(
            Review::query()
                ->with(['book' => fn ($q) => $q->withTrashed(), 'customer'])
                ->when($request->has('visible'), fn ($q) => $q->where('is_approved', $request->boolean('visible')))
                ->when($data['book_id'] ?? null, fn ($q, $id) => $q->where('book_id', $id))
                ->when($data['max_rating'] ?? null, fn ($q, $r) => $q->where('rating', '<=', $r))
                ->when($data['q'] ?? null, fn ($q, $term) => $q->where('comment', 'ilike', '%'.addcslashes($term, '%_').'%'))
                ->latest()
                ->orderByDesc('id')
                ->paginate($data['per_page'] ?? 20)
                ->withQueryString()
        );
    }

    public function hide(Request $request, Review $review): StaffReviewResource
    {
        return $this->setVisibility($request, $review, false);
    }

    public function show(Request $request, Review $review): StaffReviewResource
    {
        return $this->setVisibility($request, $review, true);
    }

    private function setVisibility(Request $request, Review $review, bool $visible): StaffReviewResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        if ($review->is_approved !== $visible) {
            $review->update(['is_approved' => $visible]);
            $this->audit->custom($request->user(), $visible ? 'shown' : 'hidden', $review,
                ['is_visible' => ! $visible], ['is_visible' => $visible, 'note' => $data['note'] ?? null]);
        }

        return new StaffReviewResource($review->load(['book' => fn ($q) => $q->withTrashed(), 'customer']));
    }
}

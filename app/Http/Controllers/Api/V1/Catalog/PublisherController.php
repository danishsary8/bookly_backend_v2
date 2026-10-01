<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublisherResource;
use App\Models\Publisher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PublisherController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return PublisherResource::collection(
            Publisher::query()
                ->withCount(['books' => fn ($q) => $q->visible()])
                ->when($data['q'] ?? null, fn ($q, $term) => $q->where('name', 'ilike', '%'.addcslashes($term, '%_').'%'))
                ->orderBy('name')
                ->paginate($data['per_page'] ?? 20)
                ->withQueryString()
        );
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthorResource;
use App\Models\Author;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuthorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return AuthorResource::collection(
            Author::query()
                ->withCount(['books' => fn ($q) => $q->visible()])
                ->when($data['q'] ?? null, fn ($q, $term) => $q->where('name', 'ilike', '%'.addcslashes($term, '%_').'%'))
                ->orderBy('name')
                ->paginate($data['per_page'] ?? 20)
                ->withQueryString()
        );
    }

    /** Author profile. The author's books come from GET /books?author_id={id}. */
    public function show(Author $author): AuthorResource
    {
        return new AuthorResource($author->loadCount(['books' => fn ($q) => $q->visible()]));
    }
}

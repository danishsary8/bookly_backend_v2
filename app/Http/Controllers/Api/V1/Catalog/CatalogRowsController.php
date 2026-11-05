<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookResource;
use App\Services\Catalog\CatalogRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogRowsController extends Controller
{
    public function __invoke(Request $request, CatalogRows $rows): JsonResponse
    {
        $input = $request->validate(['category_id' => ['nullable', 'integer', 'min:1', 'exists:categories,id']]);

        return response()->json(['data' => array_map(fn ($row) => [
            ...$row, 'books' => BookResource::collection($row['books']),
        ], $rows->rows(isset($input['category_id']) ? (int) $input['category_id'] : null))]);
    }
}

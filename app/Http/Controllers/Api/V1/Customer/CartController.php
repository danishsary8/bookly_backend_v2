<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\CartResource;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(private readonly CartService $carts) {}

    public function show(Request $request): CartResource
    {
        return new CartResource($this->carts->summary($request->user()));
    }

    public function addItem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'book_variant_id' => ['required', 'integer', 'exists:book_variants,id'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:'.CartService::MAX_PER_LINE],
        ]);

        $this->carts->add($request->user(), $data['book_variant_id'], $data['quantity'] ?? 1);

        return (new CartResource($this->carts->summary($request->user())))->response()->setStatusCode(201);
    }

    public function updateItem(Request $request, int $item): CartResource
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:'.CartService::MAX_PER_LINE]]);

        $this->carts->setQuantity($request->user(), $item, $data['quantity']);

        return new CartResource($this->carts->summary($request->user()));
    }

    public function removeItem(Request $request, int $item): CartResource
    {
        $this->carts->remove($request->user(), $item);

        return new CartResource($this->carts->summary($request->user()));
    }

    public function clear(Request $request): CartResource
    {
        $this->carts->clear($request->user());

        return new CartResource($this->carts->summary($request->user()));
    }
}

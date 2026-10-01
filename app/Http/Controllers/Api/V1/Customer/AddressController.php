<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\AddressResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AddressController extends Controller
{
    public const MAX_ADDRESSES = 10;

    public function index(Request $request): AnonymousResourceCollection
    {
        return AddressResource::collection(
            $request->user()->addresses()->orderByDesc('is_default')->latest()->orderByDesc('id')->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $request->user();
        $data = $request->validate($this->rules());

        $address = DB::transaction(function () use ($customer, $data) {
            // Lock the customer row so two quick requests cannot both pass the limit or both become default.
            Customer::whereKey($customer->id)->lockForUpdate()->first();
            $count = $customer->addresses()->count();

            abort_if($count >= self::MAX_ADDRESSES, 422, 'You can save up to '.self::MAX_ADDRESSES.' addresses. Delete one first.');

            $makeDefault = $count === 0 || ($data['is_default'] ?? false);
            if ($makeDefault) {
                $customer->addresses()->update(['is_default' => false]);
            }

            return $customer->addresses()->create([...$data, 'is_default' => $makeDefault]);
        });

        return (new AddressResource($address))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $address): AddressResource
    {
        $customer = $request->user();
        $model = $customer->addresses()->findOrFail($address);
        $data = $request->validate($this->rules(partial: true));

        DB::transaction(function () use ($customer, $model, $data) {
            if (($data['is_default'] ?? null) === true) {
                $customer->addresses()->whereKeyNot($model->id)->update(['is_default' => false]);
            }
            // Unsetting the only default would leave none; the default only moves by choosing another one.
            if (($data['is_default'] ?? null) === false && $model->is_default) {
                unset($data['is_default']);
            }

            $model->update($data);
        });

        return new AddressResource($model->refresh());
    }

    public function destroy(Request $request, int $address): JsonResponse
    {
        $customer = $request->user();
        $model = $customer->addresses()->findOrFail($address);

        DB::transaction(function () use ($customer, $model) {
            $model->delete();

            if ($model->is_default) {
                $customer->addresses()->latest()->orderByDesc('id')->first()?->update(['is_default' => true]);
            }
        });

        return response()->json(null, 204);
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'label' => ['nullable', 'string', 'max:50'],
            'recipient_name' => [$required, 'string', 'max:150'],
            'phone' => [$required, 'string', 'max:30'],
            'address_line1' => [$required, 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => [$required, 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => [$required, 'string', 'max:100'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}

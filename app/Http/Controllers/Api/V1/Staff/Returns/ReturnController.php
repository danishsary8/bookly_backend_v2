<?php

namespace App\Http\Controllers\Api\V1\Staff\Returns;

use App\Enums\ReturnStatus;
use App\Http\Controllers\Api\V1\Customer\ReturnController as CustomerReturnController;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffReturnResource;
use App\Models\OrderReturn;
use App\Services\Returns\ReturnService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReturnController extends Controller
{
    public function __construct(private readonly ReturnService $returns) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::enum(ReturnStatus::class)],
            'q' => ['nullable', 'string', 'max:190'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $term = isset($data['q']) ? '%'.addcslashes($data['q'], '%_').'%' : null;

        return StaffReturnResource::collection(
            OrderReturn::query()
                ->with([...CustomerReturnController::relations(), 'customer', 'handledBy'])
                ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
                ->when($term, fn ($q) => $q->where(fn ($w) => $w
                    ->whereHas('order', fn ($o) => $o->where('order_number', 'ilike', $term))
                    ->orWhereHas('customer', fn ($c) => $c->withTrashed()->where('email', 'ilike', $term)->orWhere('name', 'ilike', $term))))
                ->latest('requested_at')
                ->orderByDesc('id')
                ->paginate($data['per_page'] ?? 20)
                ->withQueryString()
        );
    }

    public function show(int $return): StaffReturnResource
    {
        return new StaffReturnResource($this->find($return));
    }

    public function approve(Request $request, int $return): StaffReturnResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $this->returns->approve($this->find($return), $request->user(), $data['note'] ?? null);

        return new StaffReturnResource($this->find($return));
    }

    public function reject(Request $request, int $return): StaffReturnResource
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $this->returns->reject($this->find($return), $request->user(), $data['note']);

        return new StaffReturnResource($this->find($return));
    }

    public function refund(Request $request, int $return): StaffReturnResource
    {
        $hasExplicitMethod = $request->has('method');
        $data = $request->validate([
            'method' => [$hasExplicitMethod ? 'required' : 'nullable', 'string', 'in:store_credit,manual_offline'],
            'note' => ['nullable', 'string', 'max:1000'],
            'reference' => [$hasExplicitMethod && $request->input('method') === 'manual_offline' ? 'required' : 'nullable', 'string', 'max:255'],
        ]);

        $orderReturn = $this->find($return);
        if ($orderReturn->refunded_at !== null || $orderReturn->status === ReturnStatus::Refunded) {
            throw ValidationException::withMessages([
                'status' => 'This return has already been refunded.',
            ]);
        }

        $method = $data['method'] ?? 'manual_offline';
        $reference = $data['reference'] ?? ($method === 'manual_offline' ? ($data['note'] ?? 'Manual offline refund') : null);

        $this->returns->refund(
            $orderReturn,
            $request->user(),
            $data['note'] ?? null,
            $method,
            $reference
        );

        return new StaffReturnResource($this->find($return));
    }

    private function find(int $return): OrderReturn
    {
        return OrderReturn::with([...CustomerReturnController::relations(), 'customer', 'handledBy', 'refundedBy'])->findOrFail($return);
    }
}

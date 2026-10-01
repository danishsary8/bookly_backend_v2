<?php

namespace App\Http\Controllers\Api\V1\Staff\Admin;

use App\Enums\StaffRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\StaffMemberResource;
use App\Models\StaffUser;
use App\Services\Staff\StaffManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class StaffMemberController extends Controller
{
    public function __construct(private readonly StaffManagementService $staff) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:190'],
            'role' => ['nullable', Rule::enum(StaffRole::class)],
            'active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $term = isset($data['q']) ? '%'.addcslashes($data['q'], '%_').'%' : null;

        return StaffMemberResource::collection(
            StaffUser::query()
                ->when($term, fn ($q) => $q->where(fn ($w) => $w->where('name', 'ilike', $term)->orWhere('email', 'ilike', $term)))
                ->when($data['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
                ->when($request->has('active'), fn ($q) => $q->where('is_active', $request->boolean('active')))
                ->orderBy('name')
                ->paginate($data['per_page'] ?? 20)
                ->withQueryString()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', Rule::unique('staff_users', 'email')],
            'role' => ['required', Rule::enum(StaffRole::class)],
        ]);

        return (new StaffMemberResource($this->staff->create($request->user(), $data)->refresh()))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, StaffUser $member): StaffMemberResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'role' => ['sometimes', 'required', Rule::enum(StaffRole::class)],
        ]);

        return new StaffMemberResource($this->staff->update($request->user(), $member, $data));
    }

    public function deactivate(Request $request, StaffUser $member): StaffMemberResource
    {
        return new StaffMemberResource($this->staff->setActive($request->user(), $member, false));
    }

    public function activate(Request $request, StaffUser $member): StaffMemberResource
    {
        return new StaffMemberResource($this->staff->setActive($request->user(), $member, true));
    }

    public function resetTwoFactor(Request $request, StaffUser $member): StaffMemberResource
    {
        return new StaffMemberResource($this->staff->resetTwoFactor($request->user(), $member));
    }

    public function resendInvitation(StaffUser $member): JsonResponse
    {
        $this->staff->sendInvitation($member);

        return response()->json(['message' => 'A new setup code has been emailed.']);
    }
}

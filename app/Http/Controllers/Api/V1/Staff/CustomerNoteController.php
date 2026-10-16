<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerNoteResource;
use App\Models\Customer;
use App\Models\CustomerNote;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerNoteController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Customer $customer): AnonymousResourceCollection
    {
        $notes = $customer->notes()->with('author')->get();

        return CustomerNoteResource::collection($notes);
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        $note = $customer->notes()->create([
            'staff_user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $this->audit->custom(
            $request->user(),
            'created',
            $note,
            null,
            ['customer_id' => $customer->id, 'body' => $note->body]
        );

        return (new CustomerNoteResource($note->load('author')))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, CustomerNote $note): JsonResponse
    {
        $user = $request->user();

        if ($note->staff_user_id !== $user->id && ! $user->isAdmin()) {
            return response()->json(['message' => 'You can only delete your own notes.'], 403);
        }

        $this->audit->custom(
            $user,
            'deleted',
            $note,
            ['customer_id' => $note->customer_id, 'body' => $note->body],
            null
        );

        $note->delete();

        return response()->json(null, 204);
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SupportConversationResource;
use App\Models\Order;
use App\Models\StaffUser;
use App\Models\SupportConversation;
use App\Models\SupportRating;
use App\Rules\Turnstile;
use App\Services\Support\SupportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SupportController extends Controller
{
    private function staff(Request $request): bool
    {
        return $request->is('api/v1/staff/*');
    }

    private function query(Request $request)
    {
        return SupportConversation::query()->when(! $this->staff($request), fn ($q) => $q->where('customer_id', $request->user()->id));
    }

    private function unread(Request $request): \Closure
    {
        return fn ($q) => $q->where('sender', $this->staff($request) ? 'customer' : 'staff')->where('is_internal', false)->whereNull('read_at');
    }

    public function index(Request $request)
    {
        $input = $request->validate(['status' => ['nullable', Rule::in(['open', 'pending', 'closed'])], 'topic' => ['nullable', Rule::in(SupportConversation::TOPICS)], 'assigned_to_me' => ['nullable', 'boolean'], 'unread' => ['nullable', 'boolean']]);
        $query = $this->query($request)->withCount(['messages as unread_count' => $this->unread($request)])
            ->when($input['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($input['topic'] ?? null, fn ($q, $topic) => $q->where('topic', $topic));
        if ($this->staff($request)) {
            $query->with('customer')->when($request->boolean('assigned_to_me'), fn ($q) => $q->where('assigned_staff_id', $request->user()->id));
        }
        if ($request->boolean('unread')) {
            $query->whereHas('messages', $this->unread($request));
        }
        $page = $query->orderByDesc('last_message_at')->orderByDesc('id')->paginate(25);
        $etag = '"'.hash('sha256', $page->getCollection()->map(fn ($row) => [$row->id, $row->version, $row->unread_count])->toJson().$page->total()).'"';
        if ($request->header('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag)->header('Cache-Control', 'private, no-cache');
        }

        return SupportConversationResource::collection($page)->response()->header('ETag', $etag)->header('Cache-Control', 'private, no-cache');
    }

    public function show(Request $request, int $conversation)
    {
        $input = $request->validate(['before_id' => ['nullable', 'integer', 'min:1']]);
        $model = $this->query($request)->findOrFail($conversation);
        if (! $this->staff($request) && (! $model->last_customer_seen_at || $model->last_customer_seen_at->lt(now()->subSeconds(30)))) {
            SupportConversation::whereKey($model->id)->update(['last_customer_seen_at' => now()]);
        }
        $etag = '"'.hash('sha256', $model->id.':'.$model->version.':'.($this->staff($request) ? 'staff' : 'customer').':'.($input['before_id'] ?? 0)).'"';
        if ($request->header('If-None-Match') === $etag) {
            return response('', 304)->header('ETag', $etag)->header('Cache-Control', 'private, no-cache');
        }
        $model->load('rating')->loadCount(['messages as unread_count' => $this->unread($request)]);
        if ($this->staff($request)) {
            $model->load('customer');
        }
        $messages = $model->messages()->when(! $this->staff($request), fn ($q) => $q->where('is_internal', false))
            ->when($input['before_id'] ?? null, fn ($q, $id) => $q->where('id', '<', $id))->orderByDesc('id')->limit(100)->get()->reverse()->values();
        $model->setRelation('messages', $messages);

        return (new SupportConversationResource($model))->response()->header('ETag', $etag)->header('Cache-Control', 'private, no-cache');
    }

    public function store(Request $request, SupportService $support)
    {
        $input = $request->validate(['topic' => ['required', Rule::in(SupportConversation::TOPICS)], 'order_id' => ['nullable', 'integer'], 'message' => $this->bodyRules()]);
        if (isset($input['order_id'])) {
            Order::where('customer_id', $request->user()->id)->findOrFail($input['order_id']);
        }
        $model = $support->start($request->user(), $input);

        return (new SupportConversationResource($model->load(['rating', 'messages'])))->response()->setStatusCode(201)->header('Cache-Control', 'private, no-cache');
    }

    public function guest(Request $request, SupportService $support)
    {
        $input = $request->validate(['name' => ['required', 'string', 'max:100', 'not_regex:/[<>]/'], 'email' => ['required', 'email', 'max:255'], 'message' => $this->bodyRules(), 'turnstile_token' => [new Turnstile($request->ip())]]);
        $model = $support->start(null, $input);

        // Guests receive only a reference; no public conversation access token or customer data is issued.
        return response()->json(['data' => ['id' => $model->id]], 201)->header('Cache-Control', 'no-store');
    }

    public function message(Request $request, int $conversation, SupportService $support)
    {
        $input = $request->validate(['message' => $this->bodyRules(), 'is_internal' => ['sometimes', 'boolean']]);
        $model = $this->query($request)->findOrFail($conversation);
        $staff = $this->staff($request);
        $support->message($model, $input['message'], $staff ? $request->user() : null, $staff && ($input['is_internal'] ?? false));

        return response()->noContent();
    }

    public function read(Request $request, int $conversation, SupportService $support)
    {
        $support->markRead($this->query($request)->findOrFail($conversation), $this->staff($request));

        return response()->noContent();
    }

    public function status(Request $request, int $conversation)
    {
        $input = $request->validate(['status' => ['required', Rule::in($this->staff($request) ? ['open', 'pending', 'closed'] : ['closed'])]]);
        DB::transaction(function () use ($request, $conversation, $input) {
            $model = $this->query($request)->lockForUpdate()->findOrFail($conversation);
            if ($model->anonymised_at) {
                throw ValidationException::withMessages(['status' => __('support.closed')]);
            }
            $model->status = $input['status'];
            $model->closed_at = $input['status'] === 'closed' ? ($model->closed_at ?? now()) : null;
            $model->version++;
            $model->save();
        });

        return response()->noContent();
    }

    public function assign(Request $request, int $conversation)
    {
        $input = $request->validate(['staff_id' => ['present', 'nullable', 'integer']]);
        if ($input['staff_id'] !== null) {
            $staff = StaffUser::where('is_active', true)->findOrFail($input['staff_id']);
            abort_unless($staff->hasPermission('support:manage'), 422);
        }
        DB::transaction(function () use ($conversation, $input) {
            $model = SupportConversation::lockForUpdate()->findOrFail($conversation);
            $model->assigned_staff_id = $input['staff_id'];
            $model->version++;
            $model->save();
        });

        return response()->noContent();
    }

    public function rate(Request $request, int $conversation)
    {
        $input = $request->validate(['stars' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:500', 'not_regex:/[<>]/']]);
        DB::transaction(function () use ($request, $conversation, $input) {
            $model = $this->query($request)->lockForUpdate()->findOrFail($conversation);
            if ($model->status !== 'closed' || $model->rating()->exists()) {
                throw ValidationException::withMessages(['stars' => __('support.rate_closed')]);
            }
            SupportRating::create(['conversation_id' => $model->id, ...$input]);
            $model->version++;
            $model->save();
        });

        return response()->noContent();
    }

    private function bodyRules(): array
    {
        return ['required', 'string', 'max:2000', 'not_regex:/[<>]/'];
    }
}

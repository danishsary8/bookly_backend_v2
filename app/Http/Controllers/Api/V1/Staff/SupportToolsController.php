<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\StaffUser;
use App\Models\SupportQuickReply;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupportToolsController extends Controller
{
    public function index()
    {
        return response()->json(['data' => SupportQuickReply::orderBy('id')->get()]);
    }

    public function store(Request $request)
    {
        $model = SupportQuickReply::create($request->validate($this->rules()));
        app(AuditLogger::class)->custom($request->user(), 'created', $model, null, $model->getAttributes());

        return response()->json(['data' => $model], 201);
    }

    public function update(Request $request, SupportQuickReply $reply)
    {
        $before = $reply->getAttributes();
        $reply->update($request->validate($this->rules()));
        app(AuditLogger::class)->custom($request->user(), 'updated', $reply, $before, $reply->getAttributes());

        return response()->json(['data' => $reply]);
    }

    public function destroy(Request $request, SupportQuickReply $reply)
    {
        app(AuditLogger::class)->custom($request->user(), 'deleted', $reply, $reply->getAttributes(), null);
        $reply->delete();

        return response()->noContent();
    }

    public function staff()
    {
        return response()->json(['data' => StaffUser::where('is_active', true)->get()->filter(fn ($staff) => $staff->hasPermission('support:manage'))->map(fn ($staff) => ['id' => $staff->id, 'name' => $staff->name])->values()]);
    }

    public function bindTelegram(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403);
        $input = $request->validate(['staff_id' => ['required', 'integer', 'exists:staff_users,id'], 'telegram_user_id' => ['present', 'nullable', 'integer', 'min:1']]);
        $staff = StaffUser::findOrFail($input['staff_id']);
        abort_unless($staff->is_active && $staff->hasPermission('support:manage'), 422);
        $request->validate(['telegram_user_id' => ['nullable', Rule::unique('staff_users', 'support_telegram_user_id')->ignore($staff->id)]]);
        $staff->forceFill(['support_telegram_user_id' => $input['telegram_user_id']])->save();
        // Audit identity/link action, never tokens or message contents.
        app(AuditLogger::class)->custom($request->user(), 'updated', $staff, null, ['support_telegram_linked' => $input['telegram_user_id'] !== null]);

        return response()->noContent();
    }

    private function rules(): array
    {
        return ['title' => ['required', 'string', 'max:100', 'not_regex:/[<>]/'], 'body_en' => ['required', 'string', 'max:2000', 'not_regex:/[<>]/'], 'body_km' => ['required', 'string', 'max:2000', 'not_regex:/[<>]/']];
    }
}

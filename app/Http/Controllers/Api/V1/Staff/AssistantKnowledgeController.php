<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\AssistantFaq;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AssistantKnowledgeController extends Controller
{
    public function index()
    {
        return response()->json(['data' => AssistantFaq::orderBy('id')->paginate(25)]);
    }

    public function unanswered()
    {
        $page = DB::table('assistant_unanswered')->select('id', 'question', 'count', 'last_seen')->orderByDesc('last_seen')->paginate(25);
        $page->getCollection()->transform(fn ($row) => [
            'id' => (int) $row->id, 'question' => $row->question, 'count' => (int) $row->count,
            'last_seen' => Carbon::parse($row->last_seen, 'UTC')->toIso8601String(),
        ]);

        return response()->json(['data' => $page]);
    }

    public function store(Request $request, AuditLogger $audit)
    {
        $input = $request->validate($this->rules());
        $faq = DB::transaction(function () use ($input, $request, $audit) {
            $faq = AssistantFaq::create(collect($input)->except('unanswered_id')->all());
            if (isset($input['unanswered_id'])) {
                DB::table('assistant_unanswered')->where('id', $input['unanswered_id'])->delete();
            }

            $audit->custom($request->user(), 'created', $faq, null, ['faq_created' => true]);

            return $faq->refresh();
        });

        return response()->json(['data' => $faq], 201);
    }

    public function update(Request $request, AssistantFaq $faq, AuditLogger $audit)
    {
        $faq->update($request->validate($this->rules()));
        $audit->custom($request->user(), 'updated', $faq, null, ['faq_updated' => true]);

        return response()->json(['data' => $faq]);
    }

    public function destroy(Request $request, AssistantFaq $faq, AuditLogger $audit)
    {
        $audit->custom($request->user(), 'deleted', $faq, ['faq_deleted' => true], null);
        $faq->delete();

        return response()->noContent();
    }

    private function rules(): array
    {
        return [
            'question_en' => ['required', 'string', 'max:300', 'not_regex:/[<>]/'],
            'question_km' => ['required', 'string', 'max:300', 'not_regex:/[<>]/'],
            'answer_en' => ['required', 'string', 'max:2000', 'not_regex:/[<>]/'],
            'answer_km' => ['required', 'string', 'max:2000', 'not_regex:/[<>]/'],
            'keywords' => ['required', 'array', 'min:1', 'max:20'],
            'keywords.*' => ['required', 'string', 'max:100', 'not_regex:/[<>]/'],
            'is_active' => ['required', 'boolean'],
            'unanswered_id' => ['sometimes', 'integer', 'exists:assistant_unanswered,id'],
        ];
    }
}

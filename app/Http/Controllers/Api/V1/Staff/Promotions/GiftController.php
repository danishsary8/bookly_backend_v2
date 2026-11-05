<?php

namespace App\Http\Controllers\Api\V1\Staff\Promotions;

use App\Http\Controllers\Controller;
use App\Models\Gift;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GiftController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Gift::orderBy('id')->get()]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $gift = Gift::create($request->validate($this->rules()));
        $audit->custom($request->user(), 'created', $gift, null, $gift->getAttributes());

        return response()->json(['data' => $gift], 201);
    }

    public function update(Request $request, Gift $gift, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate($this->rules('sometimes'));
        $gift = DB::transaction(function () use ($request, $gift, $data, $audit) {
            $locked = Gift::whereKey($gift->id)->lockForUpdate()->firstOrFail();
            $before = $locked->getAttributes();
            $locked->update($data);
            $audit->custom($request->user(), 'updated', $locked, $before, $locked->getAttributes());

            return $locked;
        });

        return response()->json(['data' => $gift]);
    }

    private function rules(string $required = 'required'): array
    {
        return [
            'name_en' => [$required, 'string', 'max:100', 'not_regex:/[<>]/'],
            'name_km' => [$required, 'string', 'max:100', 'not_regex:/[<>]/'],
            'image_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'stock' => [$required, 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

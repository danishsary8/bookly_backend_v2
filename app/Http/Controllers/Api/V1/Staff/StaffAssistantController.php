<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Services\Assistant\StaffAssistant;
use Illuminate\Http\Request;

class StaffAssistantController extends Controller
{
    public function ask(Request $request, StaffAssistant $assistant)
    {
        $input = $request->validate(['question' => ['required', 'string', 'max:300', 'not_regex:/[<>]/']]);

        return response()->json(['data' => $assistant->answer($input['question'], $request->user())])->header('Cache-Control', 'private, no-store');
    }
}

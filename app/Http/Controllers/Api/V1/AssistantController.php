<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Assistant\AssistantEngine;
use Illuminate\Http\Request;

class AssistantController extends Controller
{
    public function ask(Request $request, AssistantEngine $engine)
    {
        $input = $request->validate(['question' => ['required', 'string', 'max:300', 'not_regex:/[<>]/']]);
        $user = $request->user('sanctum');
        $customer = $user instanceof Customer && $user->tokenCan('customer') ? $user : null;

        return response()->json(['data' => $engine->answer($input['question'], $customer)])->header('Cache-Control', 'private, no-store');
    }
}

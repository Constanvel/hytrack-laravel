<?php

namespace App\Http\Controllers;

use App\Services\HyaResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HyaController extends Controller
{
    public function __invoke(Request $request, HyaResponder $hya): JsonResponse
    {
        $question = $request->validate(['question' => ['required', 'string', 'max:500']])['question'];

        return response()->json([
            'reply' => $hya->reply($question, $request->user()->patient->currentVisit()),
        ]);
    }
}

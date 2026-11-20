<?php

namespace App\Http\Controllers;

use App\Services\HyaResponder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HyaController extends Controller
{
    public function __invoke(Request $request, HyaResponder $hya): JsonResponse
    {
        // The last few messages give Gemini the context of a follow-up ("kalau itu?").
        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'history' => ['sometimes', 'array', 'max:6'],
            'history.*.role' => ['required', 'string', 'in:user,assistant'],
            'history.*.text' => ['required', 'string', 'max:2000'],
        ]);
        $history = array_map(fn (array $message) => ['role' => $message['role'], 'text' => $message['text']],
            array_values($data['history'] ?? []));

        return response()->json($hya->reply($data['question'], $request->user()->patient->currentVisit(), $history));
    }
}

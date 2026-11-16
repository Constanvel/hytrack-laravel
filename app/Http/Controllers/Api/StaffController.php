<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QueueService;
use App\Support\CheckInTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Android app: staff QR scanner, same two steps as the web dashboard. */
class StaffController extends Controller
{
    public function scan(Request $request): JsonResponse
    {
        $queue = CheckInTicket::resolve($this->code($request))->load(['patient', 'doctor', 'polyclinic']);

        return response()->json(['ticket' => CheckInTicket::summary($queue)]);
    }

    public function checkIn(Request $request, QueueService $queues): JsonResponse
    {
        $queue = $queues->checkIn(CheckInTicket::resolve($this->code($request)), $request->user()->staff);

        return response()->json(['message' => "{$queue->queue_number} berhasil check-in."]);
    }

    private function code(Request $request): string
    {
        return $request->validate(['code' => ['required', 'string', 'max:120']])['code'];
    }
}

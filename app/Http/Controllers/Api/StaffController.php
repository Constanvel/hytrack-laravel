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
        $queue = CheckInTicket::identify($this->code($request))->load(['patient', 'doctor', 'polyclinic', 'checkedInBy']);

        return response()->json(['ticket' => CheckInTicket::summary($queue)]);
    }

    public function checkIn(Request $request, QueueService $queues): JsonResponse
    {
        $code = $this->code($request);
        $queue = $queues->checkIn(CheckInTicket::resolve($code), $request->user()->staff, CheckInTicket::method($code));

        return response()->json(['message' => "{$queue->queue_number} berhasil check-in."]);
    }

    private function code(Request $request): string
    {
        return $request->validate(['code' => ['required', 'string', 'max:120']])['code'];
    }
}

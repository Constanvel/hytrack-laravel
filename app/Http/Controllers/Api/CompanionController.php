<?php

namespace App\Http\Controllers\Api;

use App\Enums\InvitationStatus;
use App\Http\Controllers\Controller;
use App\Models\CompanionShare;
use App\Services\CompanionInvitations;
use App\Support\VisitView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Android app: the same invitation rules as the web (App\Services\CompanionInvitations). */
class CompanionController extends Controller
{
    public function __construct(private readonly CompanionInvitations $invitations) {}

    public function index(Request $request): JsonResponse
    {
        $queue = $request->user()->patient->queues()->active()->latest('queue_id')->first();

        return response()->json([
            'invitations' => $queue ? $this->invitations->forVisit($queue)->map(CompanionInvitations::row(...)) : [],
        ]);
    }

    /** The link is returned once; only its hash is stored. */
    public function store(Request $request): JsonResponse
    {
        [$share, $token] = $this->invitations->invite($this->invitations->visitOf($request->user()->patient));

        return response()->json([
            'invitation' => CompanionInvitations::row($share->load('queue')),
            'link' => $this->invitations->link($token),
            'token' => $token,
        ], 201);
    }

    public function destroy(Request $request, int $share): JsonResponse
    {
        $this->invitations->revoke($this->invitations->visitOf($request->user()->patient), $share);

        return response()->json(['message' => 'Akses pendamping dicabut.']);
    }

    /** The invitation before accepting it; 404 once it is used, revoked or expired. */
    public function preview(Request $request, string $token): JsonResponse
    {
        $preview = $this->invitations->preview($token) ?? abort(404, 'Undangan tidak berlaku, sudah dipakai, atau telah berakhir.');

        return response()->json([
            'invite' => $preview['invite'],
            'canAccept' => $this->invitations->canAccept($request->user(), $preview['share']),
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $share = $this->invitations->accept($token, $request->user());

        return response()->json(['id' => $share->share_id], 201);
    }

    public function following(Request $request): JsonResponse
    {
        return response()->json([
            'patients' => $this->invitations->following($request->user())->map(fn (CompanionShare $share) => [
                'id' => $share->share_id,
                'name' => $share->queue->patient->shortName(),
                'detail' => "{$share->queue->queue_number} · Poli {$share->queue->polyclinic->polyclinic_name}",
            ]),
        ]);
    }

    public function show(Request $request, int $share): JsonResponse
    {
        $share = $this->invitations->followed($request->user(), $share);
        $status = $share->currentStatus();

        return response()->json([
            'id' => $share->share_id,
            'status' => $status->value,
            'progress' => $status === InvitationStatus::Accepted ? VisitView::forCompanion($share->queue, $share) : null,
            'ended' => $status === InvitationStatus::Accepted ? null : $status->label(),
        ]);
    }

    public function stop(Request $request, int $share): JsonResponse
    {
        $this->invitations->stop($request->user(), $share);

        return response()->json(['message' => 'Anda berhenti memantau kunjungan ini.']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\InvitationStatus;
use App\Models\CompanionShare;
use App\Services\CompanionInvitations;
use App\Support\VisitView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Family Companion. Patient side: invite, list, revoke. Companion side: accept with an
 * account, then follow each patient's visit until it ends.
 */
class CompanionController extends Controller
{
    public function __construct(private readonly CompanionInvitations $invitations) {}

    public function access(Request $request): Response
    {
        $queue = $request->user()->patient->queues()->active()->latest('queue_id')->first();

        return Inertia::render('CompanionAccess', [
            'invitations' => $queue ? $this->invitations->forVisit($queue)->map(CompanionInvitations::row(...)) : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [, $token] = $this->invitations->invite($this->invitations->visitOf($request->user()->patient));

        // Only the hash is stored, so this is the single time the link can be shown.
        Inertia::flash([
            'status' => 'Undangan pendamping dibuat.',
            'shareLink' => $this->invitations->link($token),
        ]);

        return back();
    }

    public function destroy(Request $request, int $share): RedirectResponse
    {
        $this->invitations->revoke($this->invitations->visitOf($request->user()->patient), $share);
        Inertia::flash('status', 'Akses pendamping dicabut.');

        return back();
    }

    /**
     * The invitation link. Guests are asked to sign in first and come back here; unknown,
     * used, revoked and expired links all look the same.
     */
    public function show(Request $request, string $token): HttpResponse
    {
        $preview = $this->invitations->preview($token);
        $user = $request->user();

        if ($preview && ! $user) {
            $request->session()->put('url.intended', $request->url());
        }

        return Inertia::render('CompanionInvite', [
            'token' => $token,
            'invite' => $preview['invite'] ?? null,
            'canAccept' => $this->invitations->canAccept($user, $preview['share'] ?? null),
        ])->toResponse($request)->setStatusCode($preview ? 200 : 404);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $share = $this->invitations->accept($token, $request->user());

        return redirect('/pendampingan/'.$share->share_id);
    }

    public function following(Request $request): Response
    {
        return Inertia::render('CompanionList', [
            'patients' => $this->invitations->following($request->user())->map(fn (CompanionShare $share) => [
                'id' => $share->share_id,
                'name' => $share->queue->patient->shortName(),
                'detail' => "{$share->queue->queue_number} · Poli {$share->queue->polyclinic->polyclinic_name}",
            ]),
        ]);
    }

    public function monitor(Request $request, int $share): Response
    {
        $share = $this->invitations->followed($request->user(), $share);
        // Once the visit ends or the patient revokes access, the page says so instead of 404.
        $live = $share->currentStatus() === InvitationStatus::Accepted;

        return Inertia::render('CompanionDashboard', [
            'shareId' => $share->share_id,
            'progress' => $live ? VisitView::forCompanion($share->queue, $share) : null,
            'ended' => $live ? null : $share->currentStatus()->label(),
        ]);
    }

    public function stop(Request $request, int $share): RedirectResponse
    {
        $this->invitations->stop($request->user(), $share);
        Inertia::flash('status', 'Anda berhenti memantau kunjungan ini.');

        return redirect('/pendampingan');
    }
}

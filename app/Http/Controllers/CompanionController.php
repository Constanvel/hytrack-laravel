<?php

namespace App\Http\Controllers;

use App\Exceptions\QueueRuleException;
use App\Models\CompanionShare;
use App\Services\QueueService;
use App\Support\HospitalTime;
use App\Support\VisitView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** Family Companion: a revocable, expiring, read-only link to one visit. */
class CompanionController extends Controller
{
    public function store(Request $request, QueueService $queues): RedirectResponse
    {
        $queue = $request->user()->patient->queues()->active()->latest('queue_id')->first()
            ?? throw new QueueRuleException('Tidak ada kunjungan aktif untuk dibagikan.');

        // One live link per visit: issuing a new one invalidates the previous link.
        $queues->revokeCompanions($queue);

        $token = Str::random(48);
        $queue->companionShares()->create([
            'access_token_hash' => CompanionShare::hashToken($token),
            // Ends with the visit, or at the end of the service day, whichever comes first.
            'expires_at' => HospitalTime::at($queue->service_date, '23:59:59'),
        ]);

        // Only the hash is stored, so this is the single time the link can be shown.
        Inertia::flash([
            'status' => 'Akses pendamping diaktifkan.',
            'shareLink' => url('/pantauan/'.$token),
        ]);

        return back();
    }

    public function destroy(Request $request, QueueService $queues): RedirectResponse
    {
        $queue = $request->user()->patient->queues()->active()->latest('queue_id')->first();

        if ($queue) {
            $queues->revokeCompanions($queue);
        }
        Inertia::flash('status', 'Akses pendamping dicabut.');

        return back();
    }

    /** Public: whoever holds a valid link sees the limited view. Unknown and expired links look the same. */
    public function show(Request $request, string $token): Response
    {
        $share = CompanionShare::findUsable($token);
        $queue = $share?->queue()->with(['doctor', 'polyclinic', 'patient', 'pharmacyOrder'])->first();

        $view = $queue?->queue_status->isActive() ? VisitView::forCompanion($queue) : null;

        return Inertia::render('CompanionView', ['share' => $view, 'token' => $token])
            ->toResponse($request)
            ->setStatusCode($view ? 200 : 404);
    }

    /** Public: a companion may give up their own access. */
    public function stop(string $token): RedirectResponse
    {
        CompanionShare::findUsable($token)?->update(['is_active' => false]);

        return redirect('/pantauan/'.$token);
    }
}

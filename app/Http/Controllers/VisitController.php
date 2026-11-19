<?php

namespace App\Http\Controllers;

use App\Enums\PrescriptionStatus;
use App\Enums\QueueStatus;
use App\Exceptions\QueueRuleException;
use App\Models\Doctor;
use App\Models\Queue;
use App\Services\QueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** The patient's own visit. The queue is always resolved from the signed-in patient. */
class VisitController extends Controller
{
    public function __construct(private readonly QueueService $queues) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'doctor_id' => ['required', 'integer', Rule::exists('doctors', 'doctor_id')],
            'polyclinic_id' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        // The patient always comes from the session, never from the request.
        $queue = $this->queues->book(
            $request->user()->patient,
            Doctor::findOrFail($data['doctor_id']),
            $data['date'],
            polyclinicId: isset($data['polyclinic_id']) ? (int) $data['polyclinic_id'] : null,
        );
        Inertia::flash('status', "Antrean {$queue->queue_number} berhasil diambil.");

        return redirect('/kunjungan');
    }

    public function destroy(Request $request): RedirectResponse
    {
        // Outside demo mode a patient can only cancel before check-in.
        $this->queues->cancel($this->activeVisit($request), force: config('hytrack.demo_controls'));
        Inertia::flash('status', 'Kunjungan dibatalkan.');

        return back();
    }

    /**
     * Demo controls: lets the patient account play the staff's part so the whole
     * journey can be shown with one login. Disabled unless HYTRACK_DEMO_CONTROLS is on.
     */
    public function simulate(Request $request): RedirectResponse
    {
        abort_unless(config('hytrack.demo_controls'), 403);

        $action = $request->validate([
            'action' => ['required', Rule::in(['check-in', 'examined', 'laboratory', 'medicine-ready', 'picked-up'])],
        ])['action'];
        $queue = $this->activeVisit($request);

        match ($action) {
            'check-in' => $this->queues->checkIn($queue),
            'examined' => $this->simulateExamination($queue),
            'laboratory' => $this->queues->completeLaboratory($queue),
            'medicine-ready' => $this->advancePharmacyTo($queue, PrescriptionStatus::Ready),
            'picked-up' => $this->advancePharmacyTo($queue, PrescriptionStatus::Completed),
        };

        return back();
    }

    private function activeVisit(Request $request): Queue
    {
        return $request->user()->patient->queues()->ongoing()->latest('queue_id')->first()
            ?? throw new QueueRuleException('Tidak ada kunjungan aktif.');
    }

    /** The simulated patient is called out of turn, then examined at once. */
    private function simulateExamination(Queue $queue): void
    {
        if ($queue->queue_status === QueueStatus::Waiting) {
            $this->queues->call($queue);
        }

        $this->queues->completeConsultation($queue);
    }

    private function advancePharmacyTo(Queue $queue, PrescriptionStatus $target): void
    {
        $order = $queue->pharmacyOrder ?? throw new QueueRuleException('Resep belum diterima farmasi.');

        if ($target === PrescriptionStatus::Completed && $order->prescription_status !== PrescriptionStatus::Ready) {
            throw new QueueRuleException('Obat belum siap diambil.');
        }

        while ($order->prescription_status !== $target && $order->prescription_status->next()) {
            $order = $this->queues->advancePharmacy($order);
        }
    }
}

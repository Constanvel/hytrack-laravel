<?php

namespace App\Http\Controllers;

use App\Enums\QueueStatus;
use App\Enums\StageStatus;
use App\Exceptions\QueueRuleException;
use App\Models\Doctor;
use App\Models\PatientJourney;
use App\Models\PharmacyOrder;
use App\Models\Polyclinic;
use App\Models\Queue;
use App\Models\Staff;
use App\Services\QueueService;
use App\Support\HospitalTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/** Live Control Dashboard: staff run today's queue, service flow and pharmacy. */
class StaffController extends Controller
{
    public function __construct(private readonly QueueService $queues) {}

    public function index(Request $request): Response
    {
        $today = HospitalTime::today();
        $clinics = Polyclinic::with('doctors')->orderBy('polyclinic_id')->get();
        $clinic = $clinics->firstWhere('polyclinic_id', $request->integer('poli')) ?? $clinics->first();
        $doctor = $clinic?->doctors->firstWhere('doctor_id', $request->integer('dokter')) ?? $clinic?->doctors->first();

        $line = $doctor
            ? Queue::forDoctorOn($doctor->doctor_id, $today)->with(['patient', 'journeys'])
                ->orderBy('checkin_at')->orderBy('queue_id')->get()
            : collect();
        $current = $line->firstWhere('queue_status', QueueStatus::Serving);
        $row = fn (Queue $queue) => [
            'id' => $queue->queue_id,
            'number' => $queue->queue_number,
            'name' => $queue->patient->full_name,
            'checkIn' => HospitalTime::clock($queue->checkin_at),
        ];

        return Inertia::render('Staff', [
            'clinics' => $clinics->map(fn (Polyclinic $item) => [
                'id' => $item->polyclinic_id,
                'name' => $item->polyclinic_name,
                'doctors' => $item->doctors->map(fn (Doctor $doc) => ['id' => $doc->doctor_id, 'name' => $doc->doctor_name]),
            ]),
            'clinicId' => $clinic?->polyclinic_id,
            'doctor' => $doctor ? [
                'id' => $doctor->doctor_id,
                'room' => $clinic->room_location,
                'time' => $doctor->scheduleLabel(),
            ] : null,
            'stats' => [
                'waiting' => $line->where('queue_status', QueueStatus::Waiting)->count(),
                'examined' => $line->whereIn('queue_status', [QueueStatus::Laboratory, QueueStatus::Pharmacy, QueueStatus::Completed])->count(),
                'notCheckedIn' => $line->where('queue_status', QueueStatus::Booked)->count(),
            ],
            'queue' => $line->where('queue_status', QueueStatus::Waiting)->map($row)->values(),
            'current' => $current ? [
                ...$row($current),
                'startedAt' => HospitalTime::clock($current->journeys->firstWhere('stage_order', 2)?->started_at),
                'calledCount' => $current->called_count,
            ] : null,
            'laboratory' => Queue::where('service_date', $today)->where('queue_status', QueueStatus::Laboratory)
                ->with(['patient', 'polyclinic'])->orderBy('queue_id')->get()
                ->map(fn (Queue $queue) => [...$row($queue), 'clinic' => $queue->polyclinic->polyclinic_name]),
            'pharmacy' => PharmacyOrder::whereHas('queue', fn ($queue) => $queue
                ->where('service_date', $today)->where('queue_status', QueueStatus::Pharmacy))
                ->with('queue.patient')->orderBy('pharmacy_order_id')->get()
                ->map(fn (PharmacyOrder $order) => [
                    'id' => $order->pharmacy_order_id,
                    'pickup' => $order->pickupNumber(),
                    'number' => $order->queue->queue_number,
                    'name' => $order->queue->patient->full_name,
                    'status' => $order->prescription_status->label(),
                    'next' => $order->prescription_status->next()?->label(),
                ]),
            'history' => $this->history($today),
        ]);
    }

    /** Arrival confirmation with the number shown under the patient's QR. */
    public function checkIn(Request $request): RedirectResponse
    {
        $code = $request->validate(
            ['code' => ['required', 'string', 'max:20']],
            ['code.required' => 'Masukkan nomor check-in pasien.'],
        )['code'];

        $queue = Queue::findByCheckInCode($code)
            ?? throw new QueueRuleException('Nomor check-in tidak ditemukan.');
        $this->queues->checkIn($queue, $this->staff($request));

        return $this->done("{$queue->queue_number} berhasil check-in.");
    }

    public function callNext(Request $request, Doctor $doctor): RedirectResponse
    {
        $called = $this->queues->callNext($doctor, HospitalTime::today(), $this->staff($request));

        return $this->done("{$called->queue_number} dipanggil.");
    }

    public function recall(Queue $queue): RedirectResponse
    {
        $this->queues->recall($queue);

        return $this->done("{$queue->queue_number} dipanggil ulang.");
    }

    public function noShow(Request $request, Queue $queue): RedirectResponse
    {
        $this->queues->markNoShow($queue, $this->staff($request));

        return $this->done("{$queue->queue_number} ditandai tidak hadir.");
    }

    public function examined(Request $request, Queue $queue): RedirectResponse
    {
        $this->queues->completeConsultation($queue, $this->staff($request));

        return $this->done("{$queue->queue_number} selesai diperiksa, lanjut ke laboratorium.");
    }

    public function laboratoryDone(Request $request, Queue $queue): RedirectResponse
    {
        $this->queues->completeLaboratory($queue, $this->staff($request));

        return $this->done("{$queue->queue_number} lanjut ke farmasi.");
    }

    public function advancePharmacy(Request $request, PharmacyOrder $order): RedirectResponse
    {
        $order = $this->queues->advancePharmacy($order, $this->staff($request));

        return $this->done("{$order->pickupNumber()}: {$order->prescription_status->label()}.");
    }

    private function staff(Request $request): ?Staff
    {
        return $request->user()->staff;
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('status', $message);

        return back();
    }

    /**
     * Today's staff activity, newest first. patient_journeys keeps the last update of
     * each stage, so this is a working log, not a complete audit trail.
     *
     * @return Collection<int, string>
     */
    private function history(string $today): Collection
    {
        return PatientJourney::whereNotNull('updated_by_staff_id')
            ->whereHas('queue', fn ($queue) => $queue->where('service_date', $today))
            ->with(['queue', 'updatedBy'])
            ->get()
            ->map(fn (PatientJourney $stage) => [
                'at' => $stage->completed_at ?? $stage->started_at,
                'id' => $stage->journey_id,
                'text' => sprintf(
                    '%s · %s %s oleh %s',
                    $stage->queue->queue_number,
                    $stage->stage_name,
                    match ($stage->stage_status) {
                        StageStatus::Done => 'selesai',
                        StageStatus::Skipped => 'dilewati, pasien tidak hadir,',
                        default => 'dimulai',
                    },
                    $stage->updatedBy->full_name,
                ),
            ])
            ->sortBy([['at', 'desc'], ['id', 'desc']])
            ->take(30)
            ->map(fn (array $event) => HospitalTime::clock($event['at']).' · '.$event['text'])
            ->values();
    }
}

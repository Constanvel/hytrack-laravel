<?php

namespace App\Support;

use App\Enums\JourneyStage;
use App\Enums\PrescriptionStatus;
use App\Enums\QueueStatus;
use App\Enums\SupportStatus;
use App\Models\PharmacyOrder;
use App\Models\PrescriptionItem;
use App\Models\Queue;
use App\Models\Staff;
use App\Models\SupportRequest;
use App\Services\QueueService;
use Carbon\CarbonInterface;

/**
 * Staff dashboard data for Figma 47:2 frames 31–37: the doctor's examination, support
 * services and the prescription the pharmacy works from. What each room receives:
 * - the visit's own doctor: notes, allergy, prescription and the support result;
 * - support staff: the referral, their note and the result file;
 * - pharmacy staff: the confirmed prescription, allergy and the doctor's note to pharmacy;
 * - everyone else: status only. Examination notes never leave the doctor's room.
 */
class ClinicalBoard
{
    /** Checklists the pharmacy ticks before each step (frames 34–36), by current status. */
    public const CHECKS = [
        'received' => [
            'identity' => 'Identitas dan nomor resep sesuai',
            'legible' => 'Resep dokter terbaca lengkap',
            'dosage' => 'Jumlah dan aturan pakai ditinjau',
        ],
        'preparing' => [
            'items' => ':count item obat sesuai resep',
            'label' => 'Jumlah dan label obat sesuai',
            'etiket' => 'Aturan pakai pada etiket sesuai',
        ],
        'ready' => [
            'identity' => 'Cocokkan dua identitas pasien',
            'medicine' => 'Konfirmasi obat dan jumlahnya',
            'explain' => 'Jelaskan aturan penggunaan',
        ],
    ];

    public function __construct(private readonly Staff $viewer, private readonly string $today) {}

    /** @return list<string> keys that must be ticked before the order moves on */
    public static function requiredChecks(PharmacyOrder $order): array
    {
        $status = $order->prescription_status === PrescriptionStatus::Checking ? 'preparing' : $order->prescription_status->value;

        return array_keys(self::CHECKS[$status] ?? []);
    }

    /**
     * Pemeriksaan dokter: the doctor's own visits that need a decision today, plus the ones
     * confirmed today. Other rooms see the visits with a doctor right now, status only.
     *
     * @return list<array<string, mixed>>
     */
    public function examinations(): array
    {
        $doctorId = $this->viewer->room() === Staff::DOCTOR ? $this->viewer->doctor_id : null;

        $queues = Queue::where('service_date', $this->today)
            ->when($doctorId, fn ($query) => $query->where('doctor_id', $doctorId))
            ->where(fn ($query) => $query
                ->whereIn('queue_status', [QueueStatus::Serving, QueueStatus::Laboratory])
                ->orWhereHas('pharmacyOrder', fn ($order) => $order->where('prescription_status', PrescriptionStatus::Returned))
                ->when($doctorId, fn ($mine) => $mine->orWhereHas('examination', fn ($exam) => $exam->whereNotNull('confirmed_at'))))
            ->with(['patient', 'doctor', 'polyclinic', 'journeys.updatedBy', 'examination.items', 'examination.confirmedBy', 'supportRequest.updatedBy', 'pharmacyOrder', 'checkedInBy'])
            ->orderBy('queue_id')->get();

        $state = fn (Queue $queue) => $this->examState($queue);
        $order = ['serving' => 0, 'review' => 1, 'returned' => 2, 'support' => 3, 'confirmed' => 4];

        return $queues->sortBy([fn (Queue $a, Queue $b) => $order[$state($a)] <=> $order[$state($b)]])
            ->take(40)->map(fn (Queue $queue) => $this->examinationRow($queue))->values()->all();
    }

    /**
     * Pelayanan penunjang: open referrals of today and the ones finished today.
     *
     * @return list<array<string, mixed>>
     */
    public function support(): array
    {
        $full = in_array($this->viewer->room(), [Staff::SUPPORT, Staff::DOCTOR], true);

        return SupportRequest::whereHas('queue', fn ($queue) => $queue->where('service_date', $this->today))
            ->with(['queue.patient', 'queue.doctor', 'updatedBy'])
            ->orderByRaw("case status when 'processing' then 0 when 'waiting' then 1 else 2 end")
            ->orderBy('requested_at')->get()
            ->map(function (SupportRequest $request) use ($full) {
                // A doctor sees the details of their own patients only.
                $own = $full && ($this->viewer->room() === Staff::SUPPORT || $request->queue->doctor_id === $this->viewer->doctor_id);

                return [
                    'id' => $request->support_request_id,
                    'number' => $request->number(),
                    'queueId' => $request->queue_id,
                    'ticket' => $request->queue->queue_number,
                    'name' => $request->queue->patient->full_name,
                    'unit' => $request->unit,
                    'unitLabel' => $request->unitLabel(),
                    'test' => $request->test_name,
                    'status' => $request->status->value,
                    'statusLabel' => $request->status->label(),
                    'listLabel' => $this->supportListLabel($request),
                    'requestedAt' => HospitalTime::clock($request->requested_at),
                    'completedAt' => HospitalTime::clock($request->completed_at),
                    'doctor' => $request->queue->doctor->doctor_name,
                    'note' => $own ? $request->staff_note : null,
                    'file' => $own && $request->result_path ? [
                        'name' => $request->result_name,
                        'url' => "/petugas/penunjang/{$request->support_request_id}/hasil",
                    ] : null,
                    'by' => $request->updatedBy?->full_name,
                    'byUnit' => $request->updatedBy?->work_unit,
                    'updatedAt' => HospitalTime::clock($request->completed_at ?? $request->started_at ?? $request->requested_at),
                    'canEdit' => $this->viewer->room() === Staff::SUPPORT && $request->status !== SupportStatus::Done,
                ];
            })->values()->all();
    }

    /** Pharmacy order details for the detail screens (frames 34–37). */
    public function prescription(PharmacyOrder $order): array
    {
        $queue = $order->queue;
        $exam = $queue->examination;
        $pharmacy = $this->viewer->room() === Staff::PHARMACY;
        $statusKey = $order->prescription_status === PrescriptionStatus::Checking ? 'preparing' : $order->prescription_status->value;
        $items = $exam?->items ?? collect();

        return [
            'doctor' => $queue->doctor->doctor_name,
            'confirmedAt' => HospitalTime::clock($exam?->confirmed_at),
            'sentAt' => $exam?->confirmed_at ? $this->stamp($exam->confirmed_at) : null,
            'itemCount' => $items->count(),
            'items' => $pharmacy ? $items->map(fn (PrescriptionItem $item) => $item->row())->values() : [],
            'allergy' => $pharmacy ? ($exam?->allergy_note ?? 'tidak dilaporkan') : null,
            'doctorNote' => $pharmacy ? $exam?->pharmacy_note : null,
            'verifiedAt' => HospitalTime::clock($order->verified_at),
            'readyAt' => HospitalTime::clock($order->ready_at),
            'calledAt' => HospitalTime::clock($order->called_at),
            'completedAt' => HospitalTime::clock($order->completed_at),
            'returnedAt' => HospitalTime::clock($order->returned_at),
            'returnReason' => $order->return_reason,
            'checks' => $order->checks ?? [],
            'checklist' => collect(self::CHECKS[$statusKey] ?? [])
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => str_replace(':count', (string) $items->count(), $label)])
                ->values(),
            'counter' => config('hytrack.pharmacy_counter'),
            'canAct' => $pharmacy,
        ];
    }

    private function examState(Queue $queue): string
    {
        return match (true) {
            $queue->pharmacyOrder?->prescription_status === PrescriptionStatus::Returned => 'returned',
            $queue->examination?->confirmed_at !== null => 'confirmed',
            $queue->queue_status === QueueStatus::Serving => 'serving',
            $queue->queue_status === QueueStatus::Laboratory && $queue->supportRequest?->status === SupportStatus::Done => 'review',
            $queue->queue_status === QueueStatus::Laboratory => 'support',
            default => 'confirmed',
        };
    }

    /** @return array<string, mixed> */
    private function examinationRow(Queue $queue): array
    {
        $exam = $queue->examination;
        $mine = $this->viewer->doctor_id !== null && $this->viewer->doctor_id === $queue->doctor_id;
        $started = $queue->journey(JourneyStage::Consultation)?->started_at;
        $support = $queue->supportRequest;
        $order = $queue->pharmacyOrder;
        $state = $this->examState($queue);

        return [
            'id' => $queue->queue_id,
            'number' => $queue->queue_number,
            'name' => $queue->patient->full_name,
            'record' => $this->record($queue),
            'clinic' => $queue->polyclinic->polyclinic_name,
            'room' => $this->roomName($queue->polyclinic->room_location),
            'doctor' => $queue->doctor->doctor_name,
            'state' => $state,
            'startedAt' => HospitalTime::clock($started),
            'minutes' => $started ? (int) $started->diffInMinutes(now()) : null,
            'confirmedAt' => HospitalTime::clock($exam?->confirmed_at),
            'confirmedStamp' => $exam?->confirmed_at ? HospitalTime::local($exam->confirmed_at)->locale('id')->translatedFormat('j F Y, H.i').' WIB' : null,
            'mine' => $mine,
            'open' => $mine && app(QueueService::class)->examinationOpen($queue),
            'notes' => $mine ? $exam?->notes : null,
            'allergy' => $mine ? $exam?->allergy_note : null,
            'pharmacyNote' => $mine ? $exam?->pharmacy_note : null,
            'items' => $mine ? ($exam?->items ?? collect())->map(fn (PrescriptionItem $item) => $item->row())->values() : [],
            'itemCount' => $exam?->items->count() ?? 0,
            'support' => $support ? [
                'id' => $support->support_request_id,
                'number' => $support->number(),
                'unitLabel' => $support->unitLabel(),
                'test' => $support->test_name,
                'status' => $support->status->value,
                'statusLabel' => $support->status->label(),
                'completedAt' => HospitalTime::clock($support->completed_at),
                'note' => $mine ? $support->staff_note : null,
                'file' => $mine && $support->result_path ? [
                    'name' => $support->result_name,
                    'url' => "/petugas/penunjang/{$support->support_request_id}/hasil",
                ] : null,
            ] : null,
            'order' => $order ? [
                'id' => $order->pharmacy_order_id,
                'pickup' => $order->pickupNumber(),
                'status' => $order->prescription_status->value,
                'statusLabel' => $order->prescription_status->staffLabel(),
                'returnReason' => $order->return_reason,
            ] : null,
            'trail' => $this->trail($queue),
        ];
    }

    /**
     * Jejak pelayanan pasien (frame 32): what happened to this visit so far.
     *
     * @return list<array{time: ?string, title: string, detail: ?string}>
     */
    private function trail(Queue $queue): array
    {
        $rows = [];
        $add = function (?CarbonInterface $at, string $title, ?string $detail) use (&$rows) {
            if ($at) {
                $rows[] = ['at' => $at, 'time' => HospitalTime::clock($at), 'title' => $title, 'detail' => $detail];
            }
        };
        $consult = $queue->journey(JourneyStage::Consultation);
        $support = $queue->supportRequest;
        $exam = $queue->examination;
        $order = $queue->pharmacyOrder;

        $add($queue->checkin_at, 'Check-in terverifikasi', $queue->checkedInBy ? "Petugas {$queue->checkedInBy->full_name}" : 'Meja check-in');
        $add($consult?->started_at, 'Pemeriksaan dimulai', $queue->doctor->doctor_name);
        if ($support) {
            $add($support->requested_at, "Dirujuk ke {$support->unitLabel()}", "{$support->number()} · {$support->test_name}");
            $add($support->completed_at, 'Hasil penunjang dikirim', $support->updatedBy?->full_name);
        }
        $add($exam?->confirmed_at, 'Sudah diperiksa', 'Konfirmasi dokter tersimpan');
        if ($order) {
            $add($order->returned_at && ! $exam?->confirmed_at ? $order->returned_at : null, 'Resep dikembalikan farmasi', $order->return_reason);
            $add($order->received_at, 'Resep diterima farmasi', "{$order->pickupNumber()} · ".mb_strtolower($order->prescription_status->staffLabel()));
        }

        usort($rows, fn ($a, $b) => $a['at'] <=> $b['at']);

        return array_map(fn ($row) => collect($row)->except('at')->all(), $rows);
    }

    private function supportListLabel(SupportRequest $request): string
    {
        return match ($request->status) {
            SupportStatus::Waiting => 'Menunggu',
            SupportStatus::Processing => $request->result_path ? 'Menunggu hasil' : 'Diproses',
            SupportStatus::Done => 'Hasil terkirim',
        };
    }

    /** "•••• 0423": the last four digits of the medical record number. */
    private function record(Queue $queue): ?string
    {
        $number = preg_replace('/\D/', '', (string) $queue->patient->medical_record_number);

        return $number !== '' ? '•••• '.substr($number, -4) : null;
    }

    /** "Ruang A-204 · Gedung A, lantai 2" → "Ruang A-204". */
    private function roomName(?string $room): ?string
    {
        return $room ? explode(' · ', $room)[0] : null;
    }

    private function stamp(CarbonInterface $at): string
    {
        return HospitalTime::local($at)->locale('id')->translatedFormat('j F Y, H.i');
    }
}

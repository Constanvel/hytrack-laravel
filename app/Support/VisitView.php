<?php

namespace App\Support;

use App\Enums\JourneyStage;
use App\Enums\QueueStatus;
use App\Enums\StageStatus;
use App\Models\CompanionShare;
use App\Models\Patient;
use App\Models\Queue;
use App\Services\WaitEstimator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * What the frontend receives about a visit. Expects doctor, polyclinic, patient
 * and pharmacyOrder to be loaded.
 */
final class VisitView
{
    /** @return array<string, mixed>|null */
    public static function forPatient(?Queue $queue): ?array
    {
        if (! $queue) {
            return null;
        }

        return [
            ...self::progress($queue),
            'patientName' => $queue->patient->full_name,
            'doctor' => $queue->doctor->doctor_name,
            'dateLabel' => HospitalTime::dateLabel($queue->service_date),
            'time' => $queue->doctor->scheduleLabel(),
            'isToday' => $queue->service_date === HospitalTime::today(),
            'checkInCode' => $queue->checkInCode(),
            // No QR for a cancelled booking: it can no longer be used at the desk.
            'checkInQr' => $queue->queue_status === QueueStatus::Cancelled ? '' : CheckInTicket::payload($queue),
            'checkedInAt' => HospitalTime::clock($queue->checkin_at),
            'cancelReason' => $queue->queue_status === QueueStatus::Cancelled ? $queue->cancel_reason : null,
            'checkInBy' => HospitalTime::clock($queue->checkin_deadline),
            'leaveBy' => HospitalTime::clock($queue->recommended_departure_time),
            'leadMinutes' => config('hytrack.check_in_lead_minutes'),
            'travelMinutes' => config('hytrack.travel_minutes'),
            'companionActive' => $queue->companionShares()->live()->exists(),
            // For the summary once the visit is done (Figma 43:2 frame 10).
            'laboratoryDone' => $queue->journey(JourneyStage::Laboratory)?->stage_status === StageStatus::Done,
        ];
    }

    /**
     * The view for an accepted companion (Figma 27:303): progress, location and the journey
     * so far. Short name only; no check-in code, address or anything medical. The phone
     * number is there for the "Hubungi pasien" button the patient agreed to by inviting.
     * Expects journeys to be loaded as well.
     *
     * @return array<string, mixed>
     */
    public static function forCompanion(Queue $queue, CompanionShare $share): array
    {
        $status = $queue->queue_status;
        $stage = fn (JourneyStage $stage) => $queue->journey($stage);
        // Where the patient is, or is heading, at this stage.
        [$place, $room] = match ($status) {
            QueueStatus::Booked => ['Belum check-in', $stage(JourneyStage::CheckIn)?->room_location],
            QueueStatus::Laboratory => ['Laboratorium', $stage(JourneyStage::Laboratory)?->room_location],
            QueueStatus::Pharmacy => ['Farmasi', $stage(JourneyStage::Pharmacy)?->room_location],
            default => ['Poli '.$queue->polyclinic->polyclinic_name, $queue->polyclinic->room_location],
        };
        [$current, $next] = match ($status) {
            QueueStatus::Booked => ['Menunggu kedatangan', 'check-in di meja pendaftaran'],
            QueueStatus::Waiting => ['Menunggu pemeriksaan', 'pemeriksaan dokter'],
            QueueStatus::Serving => ['Pemeriksaan dokter', 'laboratorium'],
            QueueStatus::Laboratory => ['Pemeriksaan laboratorium', 'farmasi'],
            default => ['Farmasi · '.($queue->pharmacyOrder?->prescription_status->label() ?? 'menunggu resep'), 'pengambilan obat'],
        };
        $labels = [
            JourneyStage::CheckIn->value => 'Check-in kedatangan',
            JourneyStage::Consultation->value => 'Pemeriksaan dokter',
            JourneyStage::Laboratory->value => 'Pemeriksaan laboratorium',
            JourneyStage::Pharmacy->value => 'Farmasi',
        ];

        return [
            ...self::progress($queue),
            'patientName' => $queue->patient->shortName(),
            'phone' => $queue->patient->phone_number ? Patient::normalizePhone($queue->patient->phone_number) : null,
            'checkedInAt' => HospitalTime::clock($queue->checkin_at),
            'place' => $place,
            'placeDetail' => $room,
            // Finished stages from patient_journeys, then where the visit is now.
            'timeline' => [
                ...$queue->journeys->where('stage_status', StageStatus::Done)->map(fn ($row) => [
                    'label' => $labels[$row->stage_order],
                    'time' => HospitalTime::clock($row->completed_at),
                    'current' => false,
                ])->values(),
                ['label' => $current, 'time' => null, 'current' => true],
            ],
            'next' => $next,
            // Informasi kunjungan: the service day and the doctor's session, no doctor name.
            'day' => $queue->service_date === HospitalTime::today() ? 'Hari ini' : HospitalTime::dateLabel($queue->service_date),
            'schedule' => $queue->doctor->scheduleLabel(),
            'endsAt' => ($queue->service_date === HospitalTime::today() ? 'hari ini' : HospitalTime::dateLabel($queue->service_date))
                .' pukul '.HospitalTime::clock($share->expires_at),
        ];
    }

    /**
     * Riwayat kunjungan: every ticket of the patient, newest service date first (web and API).
     *
     * @return list<array<string, mixed>>
     */
    public static function history(Patient $patient): array
    {
        return $patient->queues()
            ->with(['polyclinic', 'doctor'])
            ->orderByDesc('service_date')->orderByDesc('queue_id')
            ->limit(100)->get()
            ->map(fn (Queue $queue) => [
                'id' => $queue->queue_id,
                'number' => $queue->queue_number,
                'clinic' => $queue->polyclinic->polyclinic_name,
                'doctor' => $queue->doctor->doctor_name,
                'date' => HospitalTime::dateLabel($queue->service_date),
                'time' => $queue->doctor->scheduleLabel(),
                // Card heading "28 Oktober 2026 · 09.00": check-in time, else the session start.
                'day' => CarbonImmutable::parse($queue->service_date)->locale('id')->translatedFormat('j F Y'),
                'clock' => HospitalTime::clock($queue->checkin_at) ?? substr($queue->doctor->scheduleLabel(), 0, 5),
                'status' => $queue->queue_status->value,
                // A booking left unconfirmed on an earlier date was never checked in.
                'statusLabel' => $queue->queue_status === QueueStatus::Booked && $queue->service_date < HospitalTime::today()
                    ? 'Tidak check-in'
                    : $queue->queue_status->label(),
                'checkInAt' => $queue->checkin_at
                    ? HospitalTime::local($queue->checkin_at)->locale('id')->translatedFormat('j M Y · H.i') : null,
                'cancelReason' => $queue->queue_status === QueueStatus::Cancelled ? $queue->cancel_reason : null,
            ])->values()->all();
    }

    /**
     * Figma 43:2 frame 08: "15–25 menit · Perkiraan siap 10.30–10.40".
     * ponytail: a fixed 25–35 minute preparation window from receipt; use pharmacy history if it drifts.
     *
     * @return array{readyIn: ?string, readyBetween: ?string}
     */
    private static function readyEstimate(?CarbonInterface $receivedAt): array
    {
        if (! $receivedAt) {
            return ['readyIn' => null, 'readyBetween' => null];
        }
        $from = CarbonImmutable::instance($receivedAt)->addMinutes(25);
        $to = $from->addMinutes(10);
        $left = fn (CarbonImmutable $at) => max(0, (int) ceil(HospitalTime::now()->diffInMinutes($at, false)));

        return [
            'readyIn' => $left($from).'–'.$left($to),
            'readyBetween' => HospitalTime::clock($from).'–'.HospitalTime::clock($to),
        ];
    }

    /** @return array<string, mixed> */
    private static function progress(Queue $queue): array
    {
        $estimator = app(WaitEstimator::class);
        $inLine = in_array($queue->queue_status, [QueueStatus::Booked, QueueStatus::Waiting], true);
        $order = $queue->pharmacyOrder;

        return [
            'ticket' => $queue->queue_number,
            'clinic' => $queue->polyclinic->polyclinic_name,
            'room' => $queue->polyclinic->room_location,
            'stage' => $queue->queue_status->value,
            'ahead' => $inLine ? $estimator->ahead($queue) : 0,
            'waitRange' => $estimator->range($queue->estimated_wait_minutes),
            'serving' => $inLine
                ? Queue::forDoctorOn($queue->doctor_id, $queue->service_date)
                    ->where('queue_status', QueueStatus::Serving)->value('queue_number')
                : null,
            'examinedAt' => HospitalTime::clock($queue->journey(JourneyStage::Consultation)?->completed_at),
            'pharmacy' => $order ? [
                'status' => $order->prescription_status->value,
                'label' => $order->prescription_status->label(),
                'pickupNumber' => $order->pickupNumber(),
                'receivedAt' => HospitalTime::clock($order->received_at),
                ...self::readyEstimate($order->received_at),
            ] : null,
            'syncedAt' => HospitalTime::clock($queue->last_synced_at),
        ];
    }
}

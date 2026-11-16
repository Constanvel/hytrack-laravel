<?php

namespace App\Support;

use App\Enums\JourneyStage;
use App\Enums\QueueStatus;
use App\Enums\StageStatus;
use App\Models\CompanionShare;
use App\Models\Patient;
use App\Models\Queue;
use App\Services\WaitEstimator;

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
            'checkInBy' => HospitalTime::clock($queue->checkin_deadline),
            'leaveBy' => HospitalTime::clock($queue->recommended_departure_time),
            'leadMinutes' => config('hytrack.check_in_lead_minutes'),
            'travelMinutes' => config('hytrack.travel_minutes'),
            'companionActive' => $queue->companionShares()->live()->exists(),
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
            'endsAt' => ($queue->service_date === HospitalTime::today() ? 'hari ini' : HospitalTime::dateLabel($queue->service_date))
                .' pukul '.HospitalTime::clock($share->expires_at),
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
            'pharmacy' => $order ? [
                'status' => $order->prescription_status->value,
                'label' => $order->prescription_status->label(),
                'pickupNumber' => $order->pickupNumber(),
            ] : null,
            'syncedAt' => HospitalTime::clock($queue->last_synced_at),
        ];
    }
}

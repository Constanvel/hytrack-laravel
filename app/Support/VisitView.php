<?php

namespace App\Support;

use App\Enums\QueueStatus;
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
            'companionActive' => $queue->companionShares()->usable()->exists(),
        ];
    }

    /**
     * The limited view for a family companion: progress and location only.
     * No full name, contact, check-in code or anything medical.
     *
     * @return array<string, mixed>
     */
    public static function forCompanion(Queue $queue): array
    {
        return [...self::progress($queue), 'patientName' => $queue->patient->shortName()];
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

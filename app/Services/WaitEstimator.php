<?php

namespace App\Services;

use App\Enums\JourneyStage;
use App\Enums\QueueStatus;
use App\Enums\StageStatus;
use App\Models\Doctor;
use App\Models\PatientJourney;
use App\Models\Queue;

/**
 * Smart Wait Time: the estimate adapts to how long the doctor's consultations
 * actually take on the day, plus any delay the doctor has reported.
 */
class WaitEstimator
{
    /** Average consultation length today, or the default until one has finished. */
    public function averageServiceMinutes(int $doctorId, string $date): float
    {
        $minutes = PatientJourney::query()
            ->where('stage_order', JourneyStage::Consultation->value)
            ->where('stage_status', StageStatus::Done)
            ->whereNotNull('started_at')
            ->whereNotNull('completed_at')
            ->whereHas('queue', fn ($queue) => $queue->forDoctorOn($doctorId, $date))
            ->get(['started_at', 'completed_at'])
            ->map(fn (PatientJourney $stage) => $stage->started_at->diffInSeconds($stage->completed_at) / 60);

        return $minutes->isEmpty()
            ? (float) config('hytrack.default_service_minutes')
            : min(60.0, max(1.0, $minutes->avg()));
    }

    /** Patients who will be called before this one. */
    public function ahead(Queue $queue): int
    {
        $waiting = Queue::forDoctorOn($queue->doctor_id, $queue->service_date)
            ->where('queue_status', QueueStatus::Waiting);

        if ($queue->queue_status === QueueStatus::Waiting) {
            $waiting->where(fn ($earlier) => $earlier
                ->where('checkin_at', '<', $queue->checkin_at)
                ->orWhere(fn ($same) => $same
                    ->where('checkin_at', $queue->checkin_at)
                    ->where('queue_id', '<', $queue->queue_id)));
        }

        return $waiting->count();
    }

    /** "15–25": estimates are always shown as a range, never as a promise. */
    public function range(?int $minutes): ?string
    {
        return $minutes === null ? null : $minutes.'–'.($minutes + config('hytrack.wait_range_minutes'));
    }

    /**
     * Recalculate the cached estimate of everyone still to be called by this doctor.
     * Called whenever the line changes, so last_synced_at marks real data changes.
     */
    public function refresh(int $doctorId, string $date): void
    {
        $average = $this->averageServiceMinutes($doctorId, $date);
        $delay = (int) Doctor::whereKey($doctorId)->value('delay_minutes');
        $estimate = fn (int $ahead) => (int) round($ahead * $average) + $delay;

        $waiting = Queue::forDoctorOn($doctorId, $date)
            ->where('queue_status', QueueStatus::Waiting)
            ->orderBy('checkin_at')
            ->orderBy('queue_id')
            ->pluck('queue_id');

        foreach ($waiting as $position => $queueId) {
            Queue::whereKey($queueId)->update([
                'estimated_wait_minutes' => $estimate($position),
                'last_synced_at' => now(),
            ]);
        }

        // Not checked in yet: they would join the end of the current line.
        Queue::forDoctorOn($doctorId, $date)
            ->where('queue_status', QueueStatus::Booked)
            ->update([
                'estimated_wait_minutes' => $estimate($waiting->count()),
                'last_synced_at' => now(),
            ]);
    }
}

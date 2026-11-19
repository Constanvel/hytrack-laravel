<?php

namespace App\Services;

use App\Enums\JourneyStage;
use App\Enums\QueueStatus;
use App\Models\Queue;
use App\Support\HospitalTime;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Service Insights for management: aggregates only, never patient identities.
 * One service date is at most a few hundred tickets, so it is loaded once
 * (with its stages) and aggregated in memory, identically on SQLite and MySQL.
 */
class ServiceInsights
{
    /** @return array<string, mixed> */
    public function report(string $date, ?int $polyclinicId = null): array
    {
        $queues = Queue::where('service_date', $date)
            ->where('queue_status', '!=', QueueStatus::Cancelled)
            ->when($polyclinicId, fn ($query) => $query->where('polyclinic_id', $polyclinicId))
            ->with(['polyclinic', 'journeys', 'pharmacyOrder'])
            ->get();

        $waits = $queues->map(fn (Queue $queue) => $this->doctorWait($queue))->filter(fn ($wait) => $wait !== null);
        $arrived = $queues->whereNotNull('checkin_at');
        $target = config('hytrack.wait_target_minutes');
        $count = fn (QueueStatus ...$statuses) => $queues->whereIn('queue_status', $statuses)->count();

        return [
            'registered' => $queues->count(),
            'completed' => $count(QueueStatus::Completed),
            'active' => $count(QueueStatus::Waiting, QueueStatus::Serving, QueueStatus::Laboratory, QueueStatus::Pharmacy),
            'notArrived' => $count(QueueStatus::Booked),
            'averageWait' => $this->average($waits),
            'withinTarget' => $this->percent($waits->filter(fn ($wait) => $wait <= $target)->count(), $waits->count()),
            'onTime' => $this->percent(
                $arrived->filter(fn (Queue $queue) => $queue->checkin_deadline && $queue->checkin_at <= $queue->checkin_deadline)->count(),
                $arrived->count(),
            ),
            'target' => $target,
            'hourly' => $queues
                ->filter(fn (Queue $queue) => $this->doctorWait($queue) !== null)
                ->groupBy(fn (Queue $queue) => HospitalTime::local($queue->checkin_at)->format('H').'.00')
                ->map(fn (Collection $hour, string $label) => [
                    'hour' => $label,
                    'wait' => $this->average($hour->map(fn (Queue $queue) => $this->doctorWait($queue))),
                ])
                ->sortKeys()->values()->all(),
            'clinics' => $queues
                ->groupBy('polyclinic_id')
                ->map(fn (Collection $clinic) => [
                    'clinic' => $clinic->first()->polyclinic->polyclinic_name,
                    'complete' => $clinic->where('queue_status', QueueStatus::Completed)->count(),
                    'wait' => $this->average($clinic->map(fn (Queue $queue) => $this->doctorWait($queue))->filter(fn ($wait) => $wait !== null)),
                ])
                ->sortByDesc('wait')->values()->all(),
            'stages' => [
                ['label' => 'Check-in → dokter', 'minutes' => $this->average($waits)],
                ['label' => 'Dokter → laboratorium selesai', 'minutes' => $this->average($queues->map(
                    fn (Queue $queue) => $this->minutes($queue->journey(JourneyStage::Laboratory)?->started_at, $queue->journey(JourneyStage::Laboratory)?->completed_at),
                )->filter(fn ($value) => $value !== null))],
                ['label' => 'Resep → obat siap', 'minutes' => $this->average($queues->map(
                    fn (Queue $queue) => $this->minutes($queue->pharmacyOrder?->received_at, $queue->pharmacyOrder?->ready_at),
                )->filter(fn ($value) => $value !== null))],
            ],
        ];
    }

    /** CSV of the per-clinic table. Aggregates only. */
    public function csv(string $date, ?int $polyclinicId = null): string
    {
        $report = $this->report($date, $polyclinicId);
        $lines = [
            "Laporan layanan HyTrack {$date} - data agregat tanpa identitas pasien",
            "Poliklinik,Selesai,Rata-rata tunggu (menit),Target {$report['target']} menit",
        ];

        foreach ($report['clinics'] as $row) {
            $lines[] = implode(',', [
                '"'.str_replace('"', '""', $row['clinic']).'"',
                $row['complete'],
                $row['wait'] ?? '',
                $row['wait'] === null ? '' : ($row['wait'] <= $report['target'] ? 'Sesuai' : 'Di atas'),
            ]);
        }

        return "\u{FEFF}".implode("\r\n", $lines)."\r\n";
    }

    /** Minutes from check-in until the doctor started, or null if not seen yet. */
    private function doctorWait(Queue $queue): ?float
    {
        return $this->minutes($queue->checkin_at, $queue->journey(JourneyStage::Consultation)?->started_at);
    }

    private function minutes(?CarbonInterface $from, ?CarbonInterface $to): ?float
    {
        return $from && $to ? $from->diffInSeconds($to) / 60 : null;
    }

    private function average(Collection $values): ?int
    {
        return $values->isEmpty() ? null : (int) round($values->avg());
    }

    private function percent(int $part, int $whole): ?int
    {
        return $whole === 0 ? null : (int) round($part / $whole * 100);
    }
}

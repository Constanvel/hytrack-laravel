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
        // Each stage is a span from one timestamp to the next (Figma 43:2 frame 24).
        $stages = collect([
            'Check-in → dokter' => fn (Queue $queue) => [$queue->checkin_at, $queue->journey(JourneyStage::Consultation)?->started_at],
            'Dokter → laboratorium' => fn (Queue $queue) => [$queue->journey(JourneyStage::Laboratory)?->started_at, $queue->journey(JourneyStage::Laboratory)?->completed_at],
            'Resep → obat siap' => fn (Queue $queue) => [$queue->pharmacyOrder?->received_at, $queue->pharmacyOrder?->ready_at],
        ])->map(function (callable $span, string $label) use ($queues) {
            $spans = $queues->map($span)->filter(fn (array $span) => $this->minutes(...$span) !== null);

            return [
                'label' => $label,
                'minutes' => $this->average($spans->map(fn (array $span) => $this->minutes(...$span))),
                'hourly' => $spans
                    ->groupBy(fn (array $span) => HospitalTime::local($span[0])->format('H').'.00')
                    ->map(fn (Collection $hour, string $label) => [
                        'hour' => $label,
                        'wait' => $this->average($hour->map(fn (array $span) => $this->minutes(...$span))),
                    ])
                    ->sortKeys()->values()->all(),
            ];
        })->values();
        $latest = $queues->flatMap(fn (Queue $queue) => [
            $queue->checkin_at, $queue->pharmacyOrder?->received_at, $queue->pharmacyOrder?->completed_at,
            ...$queue->journeys->flatMap(fn ($journey) => [$journey->started_at, $journey->completed_at]),
        ])->filter()->max();

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
            'updatedAt' => $latest ? HospitalTime::local($latest)->format('H.i') : null,
            'hourly' => $stages[0]['hourly'],
            'clinics' => $queues
                ->groupBy('polyclinic_id')
                ->map(fn (Collection $clinic) => [
                    'clinic' => $clinic->first()->polyclinic->polyclinic_name,
                    'complete' => $clinic->where('queue_status', QueueStatus::Completed)->count(),
                    'wait' => $this->average($clinic->map(fn (Queue $queue) => $this->doctorWait($queue))->filter(fn ($wait) => $wait !== null)),
                ])
                ->sortByDesc('wait')->values()->all(),
            'stages' => $stages->all(),
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

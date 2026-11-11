<?php

namespace App\Http\Controllers;

use App\Models\Polyclinic;
use App\Models\Queue;
use App\Services\ServiceInsights;
use App\Support\HospitalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Service Insights: aggregate waiting-time and performance figures. */
class ManagementController extends Controller
{
    public function index(Request $request, ServiceInsights $insights): Response
    {
        [$date, $clinicId] = $this->filters($request);

        // Today plus the most recent service dates that have tickets.
        $dates = Queue::where('service_date', '<', HospitalTime::today())
            ->distinct()->orderByDesc('service_date')->limit(6)->pluck('service_date')
            ->prepend(HospitalTime::today());

        return Inertia::render('Management', [
            'dates' => $dates->map(fn (string $value) => [
                'value' => $value,
                'label' => CarbonImmutable::parse($value)->locale('id')->translatedFormat('j M Y'),
            ]),
            'clinics' => Polyclinic::orderBy('polyclinic_id')->get()
                ->map(fn (Polyclinic $clinic) => ['id' => $clinic->polyclinic_id, 'name' => $clinic->polyclinic_name]),
            'date' => $date,
            'clinicId' => $clinicId,
            'report' => $insights->report($date, $clinicId),
        ]);
    }

    public function export(Request $request, ServiceInsights $insights): StreamedResponse
    {
        [$date, $clinicId] = $this->filters($request);

        return response()->streamDownload(
            fn () => print $insights->csv($date, $clinicId),
            "hytrack-laporan-{$date}.csv",
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /** @return array{string, int|null} */
    private function filters(Request $request): array
    {
        $filters = $request->validate([
            'tanggal' => ['nullable', 'date_format:Y-m-d'],
            'poli' => ['nullable', 'integer'],
        ]);

        return [$filters['tanggal'] ?? HospitalTime::today(), isset($filters['poli']) ? (int) $filters['poli'] : null];
    }
}

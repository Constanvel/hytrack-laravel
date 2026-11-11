<?php

namespace App\Http\Controllers;

use App\Enums\QueueStatus;
use App\Models\Doctor;
use App\Models\Notification;
use App\Models\Polyclinic;
use App\Models\Queue;
use App\Services\QueueService;
use App\Services\WaitEstimator;
use App\Support\HospitalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Read-only patient screens: clinic and doctor selection, notifications. */
class PatientController extends Controller
{
    public function clinics(WaitEstimator $estimator): Response
    {
        $waiting = Queue::where('service_date', HospitalTime::today())
            ->where('queue_status', QueueStatus::Waiting)
            ->selectRaw('polyclinic_id, count(*) as total')
            ->groupBy('polyclinic_id')
            ->pluck('total', 'polyclinic_id');

        return Inertia::render('Clinics', [
            'clinics' => Polyclinic::withCount('doctors')->orderBy('polyclinic_id')->get()
                ->map(fn (Polyclinic $clinic) => [
                    'id' => $clinic->polyclinic_id,
                    'name' => $clinic->polyclinic_name,
                    'doctors' => $clinic->doctors_count,
                    'wait' => $estimator->range($this->estimate((int) ($waiting[$clinic->polyclinic_id] ?? 0))),
                ]),
        ]);
    }

    public function doctors(Request $request, QueueService $queues, WaitEstimator $estimator): Response
    {
        $clinic = Polyclinic::with('doctors')->find($request->integer('poli'))
            ?? Polyclinic::with('doctors')->orderBy('polyclinic_id')->firstOrFail();
        $dates = $queues->bookingDates();

        // One grouped query for every doctor and date on the screen.
        $tickets = Queue::whereIn('doctor_id', $clinic->doctors->modelKeys())
            ->whereIn('service_date', $dates)
            ->where('queue_status', '!=', QueueStatus::Cancelled)
            ->selectRaw('doctor_id, service_date, queue_status, count(*) as total')
            ->groupBy('doctor_id', 'service_date', 'queue_status')
            ->get();
        $count = fn (Doctor $doctor, string $date, ?array $statuses = null) => (int) $tickets
            ->where('doctor_id', $doctor->doctor_id)
            ->where('service_date', $date)
            ->when($statuses, fn ($rows) => $rows->whereIn('queue_status', $statuses))
            ->sum('total');

        return Inertia::render('DoctorSchedule', [
            'clinic' => [
                'id' => $clinic->polyclinic_id,
                'name' => $clinic->polyclinic_name,
                'room' => $clinic->room_location,
            ],
            'days' => array_map(function (string $date) {
                $day = CarbonImmutable::parse($date)->locale('id');

                return [
                    'date' => $date,
                    'day' => $day->translatedFormat('D'),
                    'weekday' => $day->translatedFormat('l'),
                    'label' => $day->translatedFormat('j M'),
                ];
            }, $dates),
            'doctors' => $clinic->doctors
                ->where('availability_status', '!=', Doctor::OFF)
                ->map(fn (Doctor $doctor) => [
                    'id' => $doctor->doctor_id,
                    'name' => $doctor->doctor_name,
                    'time' => $doctor->scheduleLabel(),
                    'delay' => $doctor->delay_minutes ? ['minutes' => $doctor->delay_minutes, 'reason' => $doctor->delay_reason] : null,
                    'days' => collect($dates)->mapWithKeys(fn (string $date) => [$date => [
                        'quota' => max(0, config('hytrack.daily_quota') - $count($doctor, $date)),
                        'wait' => $estimator->range($this->estimate(
                            $count($doctor, $date, [QueueStatus::Booked, QueueStatus::Waiting]),
                            (int) $doctor->delay_minutes,
                        )),
                    ]]),
                ])->values(),
        ]);
    }

    public function notifications(Request $request): Response
    {
        $patient = $request->user()->patient;
        $items = $patient->notifications()->latest('notification_id')->limit(50)->get();
        $unread = $items->whereNull('read_at')->modelKeys();

        // Opening the list is the read confirmation; this response still shows what was new.
        Notification::whereKey($unread)->update(['read_at' => now()]);

        return Inertia::render('Notifications', [
            'items' => $items->map(fn (Notification $item) => [
                'id' => $item->notification_id,
                'message' => $item->message_body,
                'action' => $item->action_instruction,
                'time' => HospitalTime::local($item->sent_at)->locale('id')->translatedFormat('j M · H.i'),
                'unread' => $item->read_at === null,
            ]),
        ]);
    }

    /** Listing estimate: people in line times the default consultation length. */
    private function estimate(int $inLine, int $delay = 0): int
    {
        return $inLine * config('hytrack.default_service_minutes') + $delay;
    }
}

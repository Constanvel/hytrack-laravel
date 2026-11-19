<?php

namespace App\Http\Controllers;

use App\Enums\QueueStatus;
use App\Enums\StageStatus;
use App\Exceptions\QueueRuleException;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientJourney;
use App\Models\PharmacyOrder;
use App\Models\Polyclinic;
use App\Models\Queue;
use App\Models\Staff;
use App\Services\QueueService;
use App\Support\CheckInTicket;
use App\Support\HospitalTime;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Live Control Dashboard: staff run today's queue, service flow and pharmacy, and manage
 * bookings (create for walk-ins, list and filter, update the stage, cancel with a reason).
 * Every route is behind auth + role:staff (routes/web.php); every change goes through
 * QueueService, which records the staff member and the server time.
 */
class StaffController extends Controller
{
    /** Bookings listed at once; filters narrow it down. */
    private const BOOKING_LIMIT = 200;

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
            'checkInDesk' => config('hytrack.rooms.check_in'),
            // Master data (CRUD "Data poliklinik"), with how much still depends on each one.
            'clinicAdmin' => Polyclinic::withCount(['doctors', 'queues'])->orderBy('polyclinic_name')->get()
                ->map(fn (Polyclinic $item) => [
                    'id' => $item->polyclinic_id,
                    'name' => $item->polyclinic_name,
                    'room' => $item->room_location,
                    'doctors' => $item->doctors_count,
                    'queues' => $item->queues_count,
                ]),
            ...$this->bookings($request, $today),
        ]);
    }

    /**
     * Scan QR kedatangan (Figma 27:360), step 1: identify the ticket without changing it,
     * so staff can match the patient before confirming. A ticket that already checked in
     * is shown as such, with the original time.
     */
    public function scan(Request $request): RedirectResponse|JsonResponse
    {
        $queue = CheckInTicket::identify($this->code($request))->load(['patient', 'doctor', 'polyclinic', 'checkedInBy']);
        $summary = CheckInTicket::summary($queue);

        // The scanner asks for JSON: the answer comes back with the request itself, so a
        // background refresh cannot take it (rule errors are JSON 422, see bootstrap/app.php).
        if ($request->expectsJson()) {
            return response()->json(['ticket' => $summary]);
        }
        Inertia::flash('scan', $summary);

        return back();
    }

    /** Step 2, or the manual number: record the arrival. A ticket is never checked in twice. */
    public function checkIn(Request $request): RedirectResponse|JsonResponse
    {
        $code = $this->code($request);
        $queue = CheckInTicket::resolve($code);
        $queue = $this->queues->checkIn($queue, $this->staff($request), CheckInTicket::method($code));
        $message = "{$queue->queue_number} berhasil check-in pukul ".HospitalTime::clock($queue->checkin_at).'.';

        return $request->expectsJson() ? response()->json(['message' => $message]) : $this->done($message);
    }

    private function code(Request $request): string
    {
        return $request->validate(
            ['code' => ['required', 'string', 'max:120']],
            ['code.required' => 'Masukkan nomor check-in pasien.'],
        )['code'];
    }

    public function callNext(Request $request, Doctor $doctor): RedirectResponse
    {
        $called = $this->queues->callNext($doctor, HospitalTime::today(), $this->staff($request));

        return $this->done("{$called->queue_number} dipanggil.");
    }

    /** Call one checked-in patient from the booking detail. */
    public function call(Request $request, Queue $queue): RedirectResponse
    {
        $called = $this->queues->callWaiting($queue, $this->staff($request));

        return $this->done("{$called->queue_number} dipanggil.");
    }

    public function recall(Request $request, Queue $queue): RedirectResponse
    {
        $this->staff($request);
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

    /** Delete in the CRUD sense: the booking is cancelled, never removed, with a reason. */
    public function cancel(Request $request, Queue $queue): RedirectResponse
    {
        $reason = $request->validate(
            ['reason' => ['required', 'string', 'min:3', 'max:255']],
            [
                'reason.required' => 'Tuliskan alasan pembatalan.',
                'reason.min' => 'Alasan pembatalan minimal 3 karakter.',
                'reason.max' => 'Alasan pembatalan maksimal 255 karakter.',
            ],
        )['reason'];

        $this->queues->cancel($queue, by: $this->staff($request), reason: $reason);

        return $this->done("Booking {$queue->queue_number} dibatalkan. Kuota dokter dibuka kembali.");
    }

    /** Create: a registered patient who came to the desk, booked under the patient app's rules. */
    public function book(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'polyclinic_id' => ['required', 'integer', Rule::exists('polyclinics', 'polyclinic_id')],
            'doctor_id' => ['required', 'integer', Rule::exists('doctors', 'doctor_id')],
            'date' => ['required', 'date_format:Y-m-d'],
        ], [
            'phone.required' => 'Masukkan nomor ponsel pasien terdaftar.',
            'polyclinic_id.required' => 'Pilih poliklinik.',
            'doctor_id.required' => 'Pilih dokter.',
            'date.required' => 'Pilih tanggal kunjungan.',
        ]);

        $patient = $this->registeredPatient($data['phone'])
            ?? throw new QueueRuleException('Nomor ponsel belum terdaftar sebagai akun pasien.');

        $queue = $this->queues->book(
            $patient,
            Doctor::findOrFail($data['doctor_id']),
            $data['date'],
            $this->staff($request),
            (int) $data['polyclinic_id'],
        );

        return $this->done("Booking {$queue->queue_number} dibuat untuk {$patient->full_name} · "
            .HospitalTime::dateLabel($queue->service_date).'.');
    }

    /** Booking form, step 1: find the registered patient by phone number (JSON). */
    public function findPatient(Request $request): JsonResponse
    {
        $phone = $request->validate(['telepon' => ['required', 'string', 'max:30']])['telepon'];
        $patient = $this->registeredPatient($phone);

        if (! $patient) {
            return response()->json(['message' => 'Nomor ponsel belum terdaftar sebagai akun pasien.'], 404);
        }

        $active = $patient->queues()->ongoing()->with('polyclinic')->latest('queue_id')->first();

        return response()->json([
            'patient' => [
                'name' => $patient->full_name,
                'phone' => $patient->maskedPhone(),
                'active' => $active ? "{$active->queue_number} · {$active->polyclinic->polyclinic_name} · "
                    .HospitalTime::dateLabel($active->service_date) : null,
            ],
        ]);
    }

    /** Booking form, step 2: doctors and remaining quota for a date (JSON). */
    public function schedule(Request $request): JsonResponse
    {
        $date = $request->validate(['tanggal' => ['required', 'date_format:Y-m-d']])['tanggal'];
        $open = in_array($date, $this->queues->bookingDates(), true);

        return response()->json([
            'open' => $open,
            'clinics' => Polyclinic::with('doctors')->orderBy('polyclinic_id')->get()
                ->map(fn (Polyclinic $clinic) => [
                    'id' => $clinic->polyclinic_id,
                    'name' => $clinic->polyclinic_name,
                    'doctors' => $clinic->doctors->where('availability_status', '!=', Doctor::OFF)
                        ->map(fn (Doctor $doctor) => [
                            'id' => $doctor->doctor_id,
                            'name' => $doctor->doctor_name,
                            'time' => $doctor->scheduleLabel(),
                            'quota' => $open ? $this->queues->remainingQuota($doctor, $date) : 0,
                        ])->values(),
                ]),
        ]);
    }

    /** Patients with their own login only; family members without an account cannot sign in. */
    private function registeredPatient(string $phone): ?Patient
    {
        $digits = Patient::normalizePhone($phone);

        return strlen($digits) < 9 ? null
            : Patient::whereNotNull('user_id')->wherePhone($digits)->first();
    }

    /** Every recorded action names a staff member; an account without a staff profile may not act. */
    private function staff(Request $request): Staff
    {
        return $request->user()->staff ?? abort(403, 'Akun ini tidak terdaftar sebagai petugas.');
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('status', $message);

        return back();
    }

    /**
     * Read: bookings for any service date, filtered by date, polyclinic, status and a
     * search on the queue number, check-in number or patient name.
     *
     * @return array<string, mixed>
     */
    private function bookings(Request $request, string $today): array
    {
        $date = $request->query('tanggal', $today);
        $date = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : ($date === '' ? '' : $today);
        $clinicId = $request->integer('bpoli') ?: null;
        $status = QueueStatus::tryFrom((string) $request->query('status', ''));
        $search = trim((string) $request->query('cari', ''));

        $query = Queue::query()
            ->when($date !== '', fn ($query) => $query->where('service_date', $date))
            ->when($clinicId, fn ($query) => $query->where('polyclinic_id', $clinicId))
            ->when($status, fn ($query) => $query->where('queue_status', $status))
            ->when($search !== '', function ($query) use ($search) {
                $code = Queue::findByCheckInCode($search);
                $query->where(fn ($match) => $match
                    ->where('queue_number', 'like', '%'.str_replace(['%', '_'], '', $search).'%')
                    ->orWhereHas('patient', fn ($patient) => $patient->where('full_name', 'like', '%'.str_replace(['%', '_'], '', $search).'%'))
                    ->when($code, fn ($byCode) => $byCode->orWhere('queue_id', $code->queue_id)));
            });

        $total = (clone $query)->count();
        $rows = $query->with([
            'patient', 'doctor', 'polyclinic', 'journeys.updatedBy',
            'bookedBy', 'checkedInBy', 'noShowBy', 'cancelledBy',
        ])
            ->orderBy('service_date')->orderBy('polyclinic_id')->orderBy('doctor_id')->orderBy('queue_id')
            ->limit(self::BOOKING_LIMIT)->get();

        return [
            'bookingFilters' => [
                'date' => $date,
                'clinic' => $clinicId,
                'status' => $status?->value ?? '',
                'search' => $search,
            ],
            'bookingTotal' => $total,
            'bookings' => $rows->map(fn (Queue $queue) => $this->bookingRow($queue, $today))->values(),
            'statuses' => collect(QueueStatus::cases())->map(fn (QueueStatus $item) => [
                'value' => $item->value,
                'label' => $item->label(),
            ]),
            // The newest bookings for any date, so a booking made in the app is noticed at once.
            'recentBookings' => Queue::with(['patient', 'polyclinic'])->latest('queue_id')->limit(5)->get()
                ->map(fn (Queue $queue) => [
                    'id' => $queue->queue_id,
                    'number' => $queue->queue_number,
                    'name' => $queue->patient->full_name,
                    'clinic' => $queue->polyclinic->polyclinic_name,
                    'date' => $queue->service_date,
                    'dateLabel' => HospitalTime::dateLabel($queue->service_date),
                    'status' => $queue->queue_status->label(),
                    'bookedAt' => $this->stamp($queue->booked_at),
                ]),
            'bookingDates' => ['min' => $today, 'max' => last($this->queues->bookingDates())],
        ];
    }

    /** @return array<string, mixed> */
    private function bookingRow(Queue $queue, string $today): array
    {
        $status = $queue->queue_status;

        return [
            'id' => $queue->queue_id,
            'number' => $queue->queue_number,
            'code' => $queue->checkInCode(),
            'name' => $queue->patient->full_name,
            'phone' => $queue->patient->maskedPhone(),
            'clinic' => $queue->polyclinic->polyclinic_name,
            'doctor' => $queue->doctor->doctor_name,
            'time' => $queue->doctor->scheduleLabel(),
            'date' => $queue->service_date,
            'dateLabel' => HospitalTime::dateLabel($queue->service_date),
            'isToday' => $queue->service_date === $today,
            'status' => $status->value,
            'statusLabel' => $status->label(),
            'bookedAt' => $this->stamp($queue->booked_at),
            'bookedBy' => $queue->bookedBy?->full_name ?? ($queue->booked_at ? 'Pasien (aplikasi)' : null),
            'deadline' => HospitalTime::clock($queue->checkin_deadline),
            'checkInClosed' => $queue->checkInClosed(),
            'checkInAt' => $this->stamp($queue->checkin_at),
            'checkInBy' => $queue->checkedInBy?->full_name,
            'checkInMethod' => match ($queue->checkin_method) {
                'qr' => 'Pindai QR',
                'code' => 'Nomor check-in',
                'demo' => 'Simulasi demo (tidak resmi)',
                default => null,
            },
            'calledCount' => $queue->called_count,
            'noShowAt' => $this->stamp($queue->no_show_at),
            'noShowBy' => $queue->noShowBy?->full_name,
            'cancelledAt' => $this->stamp($queue->cancelled_at),
            'cancelledBy' => $queue->cancelled_at ? ($queue->cancelledBy?->full_name ?? 'Pasien') : null,
            'cancelReason' => $queue->cancel_reason,
            'stages' => $queue->journeys->map(fn (PatientJourney $stage) => [
                'name' => $stage->stage_name,
                'status' => match ($stage->stage_status) {
                    StageStatus::Pending => 'Belum',
                    StageStatus::Active => 'Berlangsung',
                    StageStatus::Done => 'Selesai',
                    StageStatus::Skipped => 'Dilewati',
                },
                'at' => $this->stamp($stage->completed_at ?? $stage->started_at),
                'by' => $stage->updatedBy?->full_name,
            ])->values(),
        ];
    }

    /** "19 Nov 2026 · 09.12" in hospital time. */
    private function stamp(?CarbonInterface $at): ?string
    {
        return $at ? HospitalTime::local($at)->locale('id')->translatedFormat('j M Y · H.i') : null;
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
                        StageStatus::Skipped => 'dilewati,',
                        default => 'dimulai',
                    },
                    $stage->updatedBy->full_name,
                ),
            ])
            ->filter(fn (array $event) => $event['at'] !== null)
            ->sortBy([['at', 'desc'], ['id', 'desc']])
            ->take(30)
            ->map(fn (array $event) => HospitalTime::clock($event['at']).' · '.$event['text'])
            ->values();
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\JourneyStage;
use App\Enums\PrescriptionStatus;
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
        // An archived polyclinic stays on the live dashboard while it still has ongoing queues.
        $clinics = Polyclinic::withTrashed()
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhereHas('queues', fn ($queues) => $queues->ongoing()))
            ->with('doctors')->orderBy('polyclinic_id')->get();
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
                'name' => $item->polyclinic_name.($item->trashed() ? ' (dihapus)' : ''),
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
                'registered' => $line->where('queue_status', '!=', QueueStatus::Cancelled)->count(),
            ],
            'queue' => $line->where('queue_status', QueueStatus::Waiting)->map($row)->values(),
            'current' => $current ? [
                ...$row($current),
                'startedAt' => HospitalTime::clock($started = $current->journey(JourneyStage::Consultation)?->started_at),
                'minutes' => $started ? (int) $started->diffInMinutes(now()) : null,
                'calledCount' => $current->called_count,
            ] : null,
            'flow' => $this->flow($today),
            ...$this->pharmacy($today),
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
     * Alur pelayanan (Figma 43:2 frame 21): every visit still open today, by the stage it is in.
     *
     * @return array<string, mixed>
     */
    private function flow(string $today): array
    {
        $target = config('hytrack.wait_target_minutes');
        $visits = Queue::where('service_date', $today)
            ->with(['patient', 'polyclinic', 'pharmacyOrder'])
            ->orderByRaw('checkin_at is null')->orderBy('checkin_at')->orderBy('queue_id')->get();

        return [
            'visits' => $visits->filter(fn (Queue $queue) => $queue->queue_status->isActive())
                ->map(function (Queue $queue) use ($target) {
                    $status = $queue->queue_status;
                    $waited = $queue->checkin_at ? (int) $queue->checkin_at->diffInMinutes(now()) : 0;

                    return [
                        'id' => $queue->queue_id,
                        'number' => $queue->queue_number,
                        'name' => $queue->patient->full_name,
                        'clinicId' => $queue->polyclinic_id,
                        'stage' => match ($status) {
                            QueueStatus::Booked => 'arrival',
                            QueueStatus::Waiting, QueueStatus::Serving => 'clinic',
                            QueueStatus::Laboratory => 'support',
                            default => 'pharmacy',
                        },
                        'note' => match ($status) {
                            QueueStatus::Booked => 'Belum check-in'.($queue->checkin_deadline ? ' · batas '.HospitalTime::clock($queue->checkin_deadline) : ''),
                            QueueStatus::Serving => 'Sedang dilayani · '.($queue->polyclinic->room_location ?? $queue->polyclinic->polyclinic_name),
                            QueueStatus::Waiting => "Menunggu · {$queue->polyclinic->polyclinic_name} · {$waited} mnt",
                            QueueStatus::Laboratory => 'Laboratorium · menunggu hasil',
                            default => $queue->pharmacyOrder?->prescription_status->label() ?? 'Farmasi',
                        },
                        'late' => $status === QueueStatus::Waiting && $waited > $target,
                    ];
                })->values(),
            'completed' => $visits->where('queue_status', QueueStatus::Completed)->countBy('polyclinic_id'),
        ];
    }

    /**
     * Farmasi (Figma 43:2 frame 22): open prescriptions plus today's totals.
     *
     * @return array<string, mixed>
     */
    private function pharmacy(string $today): array
    {
        $orders = PharmacyOrder::whereHas('queue', fn ($queue) => $queue->where('service_date', $today))
            ->with(['queue.patient', 'queue.polyclinic', 'updatedBy'])->orderBy('pharmacy_order_id')->get();
        $prepared = $orders->filter(fn (PharmacyOrder $order) => $order->received_at && $order->ready_at)
            ->map(fn (PharmacyOrder $order) => $order->received_at->diffInSeconds($order->ready_at) / 60);

        return [
            'pharmacy' => $orders->filter(fn (PharmacyOrder $order) => $order->queue->queue_status === QueueStatus::Pharmacy)
                ->map(fn (PharmacyOrder $order) => [
                    'id' => $order->pharmacy_order_id,
                    'pickup' => $order->pickupNumber(),
                    'number' => $order->queue->queue_number,
                    'name' => $order->queue->patient->full_name,
                    'clinic' => $order->queue->polyclinic->polyclinic_name,
                    'record' => $order->queue->patient->medical_record_number
                        ? '•••• '.substr($order->queue->patient->medical_record_number, -4) : null,
                    'receivedAt' => HospitalTime::clock($order->received_at),
                    'status' => $order->prescription_status->value,
                    'statusLabel' => $order->prescription_status->label(),
                    'next' => $order->prescription_status->next()?->label(),
                    'handledBy' => $order->updatedBy?->full_name,
                ])->values(),
            'pharmacyStats' => [
                'done' => $orders->where('prescription_status', PrescriptionStatus::Completed)->count(),
                'average' => $prepared->isEmpty() ? null : (int) round($prepared->avg()),
            ],
        ];
    }

    /**
     * Riwayat aktivitas (Figma 43:2 frame 23): today's staff actions, newest first.
     * patient_journeys keeps the last update of each stage, so this is a working log,
     * not a complete audit trail.
     *
     * @return Collection<int, array<string, string|null>>
     */
    private function history(string $today): Collection
    {
        $row = fn (?CarbonInterface $at, string $key, Queue $queue, string $activity, ?Staff $staff, string $unit, string $result) => [
            'at' => $at,
            'key' => $key,
            'time' => HospitalTime::clock($at),
            'activity' => $activity,
            'patient' => strtok($queue->patient->full_name, ' ').' · '.$queue->queue_number,
            'staff' => $staff?->full_name,
            'unit' => $unit,
            'result' => $result,
        ];

        $stages = PatientJourney::whereNotNull('updated_by_staff_id')
            ->whereHas('queue', fn ($queue) => $queue->where('service_date', $today))
            ->with(['queue.patient', 'queue.polyclinic', 'updatedBy'])->get()
            ->map(function (PatientJourney $stage) use ($row) {
                $kind = JourneyStage::from($stage->stage_order);
                $done = $stage->stage_status === StageStatus::Done;
                $unit = match ($kind) {
                    JourneyStage::CheckIn => 'Kedatangan',
                    JourneyStage::Consultation => $stage->queue->polyclinic->polyclinic_name,
                    JourneyStage::Laboratory => 'Laboratorium',
                    JourneyStage::Pharmacy => 'Farmasi',
                };
                [$activity, $result] = $stage->stage_status === StageStatus::Skipped
                    ? ["{$stage->stage_name} dilewati", 'Dilewati']
                    : match ($kind) {
                        JourneyStage::CheckIn => ['Check-in dikonfirmasi', 'Berhasil'],
                        JourneyStage::Consultation => $done ? ['Pemeriksaan dokter selesai', 'Selesai'] : ['Pemeriksaan dimulai', 'Dilayani'],
                        JourneyStage::Laboratory => $done ? ['Laboratorium selesai', 'Selesai'] : ['Laboratorium dimulai', 'Diperiksa'],
                        JourneyStage::Pharmacy => $done ? ['Obat diserahkan', 'Diserahkan'] : ['Resep diterima', 'Verifikasi'],
                    };

                return $row($stage->completed_at ?? $stage->started_at, sprintf('j%08d', $stage->journey_id), $stage->queue, $activity, $stage->updatedBy, $unit, $result);
            });

        $pharmacy = PharmacyOrder::whereNotNull('updated_by_staff_id')
            ->whereIn('prescription_status', [PrescriptionStatus::Checking, PrescriptionStatus::Ready])
            ->whereHas('queue', fn ($queue) => $queue->where('service_date', $today))
            ->with(['queue.patient', 'updatedBy'])->get()
            ->map(fn (PharmacyOrder $order) => $order->prescription_status === PrescriptionStatus::Ready
                ? $row($order->ready_at, sprintf('p%08d', $order->pharmacy_order_id), $order->queue, 'Resep siap diambil', $order->updatedBy, 'Farmasi', 'Siap diambil')
                : $row($order->prepared_at, sprintf('p%08d', $order->pharmacy_order_id), $order->queue, 'Resep selesai disiapkan', $order->updatedBy, 'Farmasi', 'Pemeriksaan akhir'));

        $noShows = Queue::where('service_date', $today)->whereNotNull('no_show_by_staff_id')
            ->with(['patient', 'polyclinic', 'noShowBy'])->get()
            ->map(fn (Queue $queue) => $row($queue->no_show_at, sprintf('n%08d', $queue->queue_id), $queue, 'Ditandai tidak hadir', $queue->noShowBy, $queue->polyclinic->polyclinic_name, 'Tidak hadir'));

        return $stages->concat($pharmacy)->concat($noShows)
            ->filter(fn (array $event) => $event['at'] !== null)
            ->sortBy([['at', 'desc'], ['key', 'desc']])
            ->map(fn (array $event) => collect($event)->except('at')->all())
            ->values();
    }
}

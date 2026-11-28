<?php

namespace App\Services;

use App\Enums\InvitationStatus;
use App\Enums\JourneyStage;
use App\Enums\PrescriptionStatus;
use App\Enums\QueueStatus;
use App\Enums\StageStatus;
use App\Enums\SupportStatus;
use App\Exceptions\QueueRuleException;
use App\Models\Doctor;
use App\Models\Examination;
use App\Models\Patient;
use App\Models\PharmacyOrder;
use App\Models\Queue;
use App\Models\Staff;
use App\Models\SupportRequest;
use App\Support\HospitalTime;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Every change to a visit goes through here, so patients, staff and the demo
 * controls all follow the same rules.
 */
class QueueService
{
    public function __construct(private readonly WaitEstimator $estimator) {}

    /** @return list<string> Service dates (Y-m-d) open for booking. */
    public function bookingDates(): array
    {
        $today = HospitalTime::now()->startOfDay();

        return array_map(
            fn (int $offset) => $today->addDays($offset)->toDateString(),
            range(0, config('hytrack.booking_days') - 1),
        );
    }

    public function remainingQuota(Doctor $doctor, string $date): int
    {
        $taken = Queue::forDoctorOn($doctor->doctor_id, $date)
            ->where('queue_status', '!=', QueueStatus::Cancelled)
            ->count();

        return max(0, config('hytrack.daily_quota') - $taken);
    }

    /**
     * Issues a ticket. The same rules apply to the patient app and to staff booking a
     * walk-in patient ($by). $polyclinicId, when given, must be the doctor's polyclinic.
     */
    public function book(Patient $patient, Doctor $doctor, string $date, ?Staff $by = null, ?int $polyclinicId = null): Queue
    {
        $queue = DB::transaction(function () use ($patient, $doctor, $date, $by, $polyclinicId) {
            // Lock order: patient, then doctor. The patient lock turns a double click (or two
            // tabs) into two requests in a row, so the second one sees the first ticket.
            Patient::whereKey($patient->patient_id)->lockForUpdate()->firstOrFail();
            // Serialises bookings per doctor so two patients cannot get the same number or
            // both take the last slot.
            $doctor = Doctor::whereKey($doctor->doctor_id)->lockForUpdate()->with('polyclinic')->firstOrFail();

            if ($polyclinicId !== null && $doctor->polyclinic_id !== $polyclinicId) {
                throw new QueueRuleException('Dokter tidak praktik di poliklinik yang dipilih.');
            }
            if ($doctor->polyclinic->trashed()) {
                throw new QueueRuleException('Poliklinik ini sudah tidak menerima antrean.');
            }
            if (! in_array($date, $this->bookingDates(), true)) {
                throw new QueueRuleException('Tanggal kunjungan tidak tersedia.');
            }
            if ($doctor->availability_status === Doctor::OFF) {
                throw new QueueRuleException('Dokter tidak praktik pada jadwal ini.');
            }
            if ($patient->queues()->ongoing()->exists()) {
                throw new QueueRuleException($by
                    ? 'Pasien ini sudah memiliki satu kunjungan aktif.'
                    : 'Anda sudah memiliki satu kunjungan aktif.');
            }
            if ($this->remainingQuota($doctor, $date) < 1) {
                throw new QueueRuleException('Kuota dokter pada tanggal ini sudah penuh.');
            }

            // Cancelled tickets keep their number, so the next number is simply the count + 1.
            $sequence = Queue::forDoctorOn($doctor->doctor_id, $date)->count() + 1;
            $checkInBy = HospitalTime::at($date, $doctor->schedule_start)
                ->subMinutes(config('hytrack.check_in_lead_minutes'));

            $queue = Queue::create([
                'patient_id' => $patient->patient_id,
                'doctor_id' => $doctor->doctor_id,
                'polyclinic_id' => $doctor->polyclinic_id,
                'service_date' => $date,
                'queue_number' => sprintf('%s-%03d', $doctor->ticketPrefix(), $sequence),
                'queue_status' => QueueStatus::Booked,
                'booked_at' => now(),
                'booked_by_staff_id' => $by?->staff_id,
                'checkin_deadline' => $checkInBy,
                'recommended_departure_time' => $checkInBy->subMinutes(config('hytrack.travel_minutes')),
            ]);

            $rooms = config('hytrack.rooms');
            $queue->journeys()->createMany([
                $this->stageRow(JourneyStage::CheckIn, $rooms['check_in'], 'Tunjukkan QR atau nomor check-in kepada petugas.'),
                $this->stageRow(JourneyStage::Consultation, $doctor->polyclinic->room_location, 'Tunggu nomor Anda dipanggil di dekat ruang poliklinik.'),
                $this->stageRow(JourneyStage::Laboratory, $rooms['laboratory'], 'Lapor di meja laboratorium dengan menunjukkan tiket.'),
                $this->stageRow(JourneyStage::Pharmacy, $rooms['pharmacy'], 'Ambil obat setelah nomor pengambilan dipanggil.'),
            ]);

            $this->notify($queue, 'booked', "Antrean {$queue->queue_number} berhasil diambil.", 'Check-in saat tiba di rumah sakit.');

            return $queue;
        });

        $this->estimator->refresh($queue->doctor_id, $queue->service_date);

        return $queue->refresh();
    }

    /**
     * Arrival confirmed. The official record names the staff member ($by) and how the ticket
     * was identified ($method: qr | code). The demo simulation passes no staff, so its
     * check-in is recognisable as unofficial (checkin_by_staff_id stays null).
     */
    public function checkIn(Queue $queue, ?Staff $by = null, ?string $method = null): Queue
    {
        $queue = $this->transition($queue, QueueStatus::Waiting, function (Queue $queue) use ($by, $method) {
            if ($queue->service_date !== HospitalTime::today()) {
                throw new QueueRuleException('Check-in hanya dapat dilakukan pada tanggal layanan.');
            }

            // Server time, never a time sent by the browser.
            $queue->checkin_at = now();
            $queue->checkin_by_staff_id = $by?->staff_id;
            $queue->checkin_method = $by ? ($method ?? 'code') : 'demo';
            $this->stage($queue, JourneyStage::CheckIn, StageStatus::Done, $by);
            $this->notify($queue, 'checked_in', 'Check-in berhasil. Anda masuk antrean dokter.', 'Tunggu di dekat ruang poliklinik.');
        });

        $this->estimator->refresh($queue->doctor_id, $queue->service_date);

        return $queue->refresh();
    }

    /** Call the patient who checked in earliest. Only one patient is served at a time. */
    public function callNext(Doctor $doctor, string $date, ?Staff $by = null): Queue
    {
        $called = DB::transaction(function () use ($doctor, $date, $by) {
            // Two desks pressing "Panggil" at once must not call two patients.
            Doctor::whereKey($doctor->doctor_id)->lockForUpdate()->firstOrFail();
            $line = Queue::forDoctorOn($doctor->doctor_id, $date);

            if ((clone $line)->where('queue_status', QueueStatus::Serving)->exists()) {
                throw new QueueRuleException('Selesaikan pasien aktif sebelum memanggil berikutnya.');
            }

            $next = (clone $line)->where('queue_status', QueueStatus::Waiting)
                ->orderBy('checkin_at')->orderBy('queue_id')
                ->lockForUpdate()->first();

            if (! $next) {
                throw new QueueRuleException('Tidak ada pasien yang menunggu.');
            }

            return $this->call($next, $by);
        });

        $this->estimator->refresh($doctor->doctor_id, $date);
        $this->notifyUpNext($doctor->doctor_id, $date);

        return $called;
    }

    /**
     * Staff call one specific checked-in patient from the booking detail, e.g. a patient
     * given priority. The doctor still serves one patient at a time.
     */
    public function callWaiting(Queue $queue, Staff $by): Queue
    {
        $called = DB::transaction(function () use ($queue, $by) {
            Doctor::whereKey($queue->doctor_id)->lockForUpdate()->firstOrFail();
            $queue = $queue->fresh();

            if ($queue->queue_status !== QueueStatus::Waiting) {
                throw new QueueRuleException('Hanya pasien yang sudah check-in dapat dipanggil.');
            }
            if ($queue->service_date !== HospitalTime::today()) {
                throw new QueueRuleException('Pasien hanya dapat dipanggil pada tanggal layanan.');
            }
            if (Queue::forDoctorOn($queue->doctor_id, $queue->service_date)->where('queue_status', QueueStatus::Serving)->exists()) {
                throw new QueueRuleException('Selesaikan pasien aktif sebelum memanggil berikutnya.');
            }

            return $this->call($queue, $by);
        });

        $this->estimator->refresh($called->doctor_id, $called->service_date);
        $this->notifyUpNext($called->doctor_id, $called->service_date);

        return $called;
    }

    /** Call one specific waiting patient. Staff use callNext(), which keeps the order. */
    public function call(Queue $queue, ?Staff $by = null): Queue
    {
        return $this->transition($queue, QueueStatus::Serving, function (Queue $queue) use ($by) {
            $queue->called_count = 1;
            $this->stage($queue, JourneyStage::Consultation, StageStatus::Active, $by);
            $this->notify($queue, 'called', "Nomor {$queue->queue_number} dipanggil.", 'Silakan masuk ke ruang pemeriksaan.');
        });
    }

    public function recall(Queue $queue): Queue
    {
        return DB::transaction(function () use ($queue) {
            $queue = Queue::whereKey($queue->queue_id)->lockForUpdate()->firstOrFail();

            if ($queue->queue_status !== QueueStatus::Serving) {
                throw new QueueRuleException('Hanya pasien yang sedang dipanggil dapat dipanggil ulang.');
            }

            $queue->increment('called_count');
            $this->notify($queue, 'recalled', "Panggilan ulang untuk nomor {$queue->queue_number}.", 'Segera menuju ruang pemeriksaan.');

            return $queue;
        });
    }

    /**
     * "Tidak hadir" is recorded in two situations only:
     * - not checked in (Booked) and the check-in deadline has passed;
     * - checked in and called (Serving), but absent after at least one repeat call.
     * Before the deadline a booked patient is simply "Belum check-in".
     */
    public function markNoShow(Queue $queue, ?Staff $by = null): Queue
    {
        $queue = $this->transition($queue, QueueStatus::NoShow, function (Queue $queue) use ($by) {
            if ($queue->queue_status === QueueStatus::Booked) {
                if (! $queue->checkInClosed()) {
                    throw new QueueRuleException('Pasien belum melewati batas check-in pukul '
                        .HospitalTime::clock($queue->checkin_deadline).'. Statusnya masih Belum check-in.');
                }
                $this->stage($queue, JourneyStage::CheckIn, StageStatus::Skipped, $by);
                $message = 'Anda tidak check-in sebelum batas waktu, sehingga ditandai tidak hadir.';
            } else {
                if ($queue->called_count < 2) {
                    throw new QueueRuleException('Tandai tidak hadir hanya setelah panggilan ulang.');
                }
                $this->stage($queue, JourneyStage::Consultation, StageStatus::Skipped, $by);
                $message = 'Anda tidak hadir saat dipanggil.';
            }

            $queue->no_show_at = now();
            $queue->no_show_by_staff_id = $by?->staff_id;
            $this->endCompanions($queue);
            $this->notify($queue, 'no_show', $message, 'Hubungi petugas di meja check-in.');
        });

        $this->estimator->refresh($queue->doctor_id, $queue->service_date);

        return $queue;
    }

    public function completeConsultation(Queue $queue, ?Staff $by = null): Queue
    {
        $queue = $this->transition($queue, QueueStatus::Laboratory, function (Queue $queue) use ($by) {
            $this->stage($queue, JourneyStage::Consultation, StageStatus::Done, $by);
            $this->stage($queue, JourneyStage::Laboratory, StageStatus::Active, $by);
            $this->notify($queue, 'laboratory', 'Pemeriksaan dokter selesai. Selanjutnya ke laboratorium.', config('hytrack.rooms.laboratory'));
        });

        // The finished consultation changes the day's average, so everyone's estimate moves.
        $this->estimator->refresh($queue->doctor_id, $queue->service_date);

        return $queue;
    }

    public function completeLaboratory(Queue $queue, ?Staff $by = null): Queue
    {
        return $this->transition($queue, QueueStatus::Pharmacy, function (Queue $queue) use ($by) {
            $this->stage($queue, JourneyStage::Laboratory, StageStatus::Done, $by);
            $this->stage($queue, JourneyStage::Pharmacy, StageStatus::Active, $by);

            PharmacyOrder::create([
                'queue_id' => $queue->queue_id,
                'updated_by_staff_id' => $by?->staff_id,
                'prescription_status' => PrescriptionStatus::Received,
                'received_at' => now(),
            ]);

            $this->notify($queue, 'pharmacy', 'Resep diterima farmasi. Obat sedang diproses.', config('hytrack.rooms.pharmacy'));
        });
    }

    /**
     * Move a prescription one step forward (Figma 47:2 frames 34–37): verify and start,
     * ready for pickup, handed over. Handing over the medicine completes the visit.
     */
    public function advancePharmacy(PharmacyOrder $order, ?Staff $by = null): PharmacyOrder
    {
        return DB::transaction(function () use ($order, $by) {
            $order = PharmacyOrder::whereKey($order->pharmacy_order_id)->lockForUpdate()->firstOrFail();

            if ($order->prescription_status === PrescriptionStatus::Returned) {
                throw new QueueRuleException('Resep sedang ditinjau ulang dokter.');
            }
            $next = $order->prescription_status->next() ?? throw new QueueRuleException('Obat sudah diserahkan.');

            $order->fill(['prescription_status' => $next, 'updated_by_staff_id' => $by?->staff_id, 'checks' => null]);

            match ($next) {
                PrescriptionStatus::Preparing => $order->verified_at = now(),
                PrescriptionStatus::Ready => $order->fill(['verified_at' => $order->verified_at ?? now(), 'prepared_at' => now(), 'ready_at' => now()]),
                PrescriptionStatus::Completed => $order->completed_at = now(),
                default => null,
            };
            $order->save();

            if ($next === PrescriptionStatus::Ready) {
                $this->notify($order->queue, 'medicine_ready', "Obat siap diambil. Nomor pengambilan {$order->pickupNumber()}.", config('hytrack.rooms.pharmacy'));
            }

            if ($next === PrescriptionStatus::Completed) {
                $this->transition($order->queue, QueueStatus::Completed, function (Queue $queue) use ($by) {
                    $this->stage($queue, JourneyStage::Pharmacy, StageStatus::Done, $by);
                    $this->endCompanions($queue);
                    $this->notify($queue, 'completed', 'Kunjungan selesai. Semoga lekas sehat.');
                });
            }

            return $order;
        });
    }

    /** "Simpan proses": the ticked checklist items, kept until the status moves on. */
    public function savePharmacyChecks(PharmacyOrder $order, Staff $by, array $checks): PharmacyOrder
    {
        return DB::transaction(function () use ($order, $by, $checks) {
            $order = PharmacyOrder::whereKey($order->pharmacy_order_id)->lockForUpdate()->firstOrFail();

            if (in_array($order->prescription_status, [PrescriptionStatus::Completed, PrescriptionStatus::Returned], true)) {
                throw new QueueRuleException('Resep ini tidak sedang diproses farmasi.');
            }
            $order->update(['checks' => array_values($checks), 'updated_by_staff_id' => $by->staff_id]);

            return $order;
        });
    }

    /** "Panggil pasien F-018": only once the medicine is ready. */
    public function callPickup(PharmacyOrder $order, Staff $by): PharmacyOrder
    {
        return DB::transaction(function () use ($order, $by) {
            $order = PharmacyOrder::whereKey($order->pharmacy_order_id)->lockForUpdate()->firstOrFail();

            if ($order->prescription_status !== PrescriptionStatus::Ready) {
                throw new QueueRuleException('Pasien dipanggil setelah obat siap diambil.');
            }
            $order->update(['called_at' => now(), 'updated_by_staff_id' => $by->staff_id]);
            $this->notify($order->queue, 'medicine_called', "Nomor {$order->pickupNumber()} dipanggil di ".config('hytrack.pharmacy_counter').'.', config('hytrack.rooms.pharmacy'));

            return $order;
        });
    }

    /**
     * "Kembalikan ke dokter" (frame 34): before preparation starts, the pharmacy sends the
     * prescription back with a reason. The doctor's new confirmation reopens the same order.
     */
    public function returnPrescription(PharmacyOrder $order, Staff $by, string $reason): PharmacyOrder
    {
        return DB::transaction(function () use ($order, $by, $reason) {
            $order = PharmacyOrder::whereKey($order->pharmacy_order_id)->lockForUpdate()->firstOrFail();

            if ($order->prescription_status !== PrescriptionStatus::Received) {
                throw new QueueRuleException('Resep hanya dapat dikembalikan sebelum obat diproses.');
            }
            $order->update([
                'prescription_status' => PrescriptionStatus::Returned,
                'returned_at' => now(),
                'return_reason' => trim($reason),
                'updated_by_staff_id' => $by->staff_id,
                'checks' => null,
            ]);
            $order->queue->examination?->update(['confirmed_at' => null, 'confirmed_by_staff_id' => null]);
            $this->notify($order->queue, 'prescription_returned', 'Resep Anda sedang ditinjau ulang oleh dokter.', 'Tunggu di area farmasi.');

            return $order;
        });
    }

    /**
     * Whether the doctor may still change the examination: not confirmed yet, or confirmed
     * and sent back by the pharmacy.
     */
    public function examinationOpen(Queue $queue): bool
    {
        return match ($queue->queue_status) {
            QueueStatus::Serving, QueueStatus::Laboratory => ! $queue->examination?->confirmed_at,
            QueueStatus::Pharmacy => $queue->pharmacyOrder?->prescription_status === PrescriptionStatus::Returned,
            default => false,
        };
    }

    /**
     * "Simpan draf" (frame 31): notes, allergy and the prescription. Only the visit's own
     * doctor writes it, and only while the examination is open.
     *
     * @param  array{notes?: ?string, allergy?: ?string, pharmacy_note?: ?string, items?: list<array<string, ?string>>}  $data
     */
    public function saveExamination(Queue $queue, Staff $doctor, array $data): Examination
    {
        return DB::transaction(function () use ($queue, $doctor, $data) {
            $queue = Queue::whereKey($queue->queue_id)->lockForUpdate()->with(['examination', 'pharmacyOrder'])->firstOrFail();
            $this->ensureOwnDoctor($queue, $doctor);

            if (! $this->examinationOpen($queue)) {
                throw new QueueRuleException('Pemeriksaan ini sudah dikonfirmasi dan tidak dapat diubah.');
            }

            $exam = $queue->examination ?? new Examination(['queue_id' => $queue->queue_id, 'doctor_id' => $queue->doctor_id]);
            $exam->fill([
                'notes' => $this->clean($data['notes'] ?? null),
                'allergy_note' => $this->clean($data['allergy'] ?? null),
                'pharmacy_note' => $this->clean($data['pharmacy_note'] ?? null),
            ])->save();

            if (array_key_exists('items', $data)) {
                $exam->items()->delete();
                foreach (array_values($data['items']) as $position => $item) {
                    $exam->items()->create([
                        'position' => $position + 1,
                        'medicine_name' => trim($item['name']),
                        'dosage_form' => $this->clean($item['form'] ?? null),
                        'quantity' => trim($item['quantity']),
                        'dosage' => trim($item['dosage']),
                        'timing' => $this->clean($item['timing'] ?? null),
                    ]);
                }
            }

            return $exam->load('items');
        });
    }

    /**
     * "Buat permintaan penunjang": the doctor refers the patient to laboratory or radiology.
     * The consultation ends here; the doctor decides again once the result is back.
     */
    public function refer(Queue $queue, Staff $doctor, string $unit, string $test): SupportRequest
    {
        $request = DB::transaction(function () use ($queue, $doctor, $unit, $test) {
            $queue = Queue::whereKey($queue->queue_id)->lockForUpdate()->with(['examination', 'supportRequest'])->firstOrFail();
            $this->ensureOwnDoctor($queue, $doctor);
            $label = config("hytrack.support_units.{$unit}") ?? throw new QueueRuleException('Pilih unit penunjang.');

            if ($queue->supportRequest && $queue->supportRequest->status !== SupportStatus::Done) {
                throw new QueueRuleException("Permintaan {$queue->supportRequest->number()} masih diproses.");
            }
            if (! in_array($queue->queue_status, [QueueStatus::Serving, QueueStatus::Laboratory], true) || $queue->examination?->confirmed_at) {
                throw new QueueRuleException('Permintaan penunjang dibuat selama pemeriksaan dokter.');
            }

            $apply = function (Queue $queue) use ($doctor, $unit, $label) {
                $this->stage($queue, JourneyStage::Consultation, StageStatus::Done, $doctor);
                $lab = $queue->journeys()->where('stage_order', JourneyStage::Laboratory->value)->firstOrFail();
                $lab->update([
                    'stage_name' => $label,
                    'room_location' => config('hytrack.rooms.'.($unit === 'radiologi' ? 'radiology' : 'laboratory')),
                    'stage_status' => StageStatus::Active,
                    'started_at' => now(),
                    'completed_at' => null,
                    'updated_by_staff_id' => $doctor->staff_id,
                ]);
                $this->notify($queue, 'referred', "Dokter merujuk Anda ke {$label}.", $lab->room_location);
            };

            if ($queue->queue_status === QueueStatus::Serving) {
                $this->transition($queue, QueueStatus::Laboratory, $apply);
            } else {
                $apply($queue);
            }

            return SupportRequest::create([
                'queue_id' => $queue->queue_id,
                'requested_by_staff_id' => $doctor->staff_id,
                'unit' => $unit,
                'test_name' => trim($test),
                'status' => SupportStatus::Waiting,
                'requested_at' => now(),
            ]);
        });

        $this->estimator->refresh($request->queue->doctor_id, $request->queue->service_date);

        return $request;
    }

    /** "Simpan status" (frame 33): Menunggu or Diproses, the staff note and the result file. */
    public function updateSupport(SupportRequest $request, Staff $by, SupportStatus $status, ?string $note, ?UploadedFile $file): SupportRequest
    {
        if ($status === SupportStatus::Done) {
            return $this->completeSupport($request, $by, $note, $file);
        }

        return DB::transaction(function () use ($request, $by, $status, $note, $file) {
            $request = $this->openSupport($request);
            $request->fill([
                'status' => $status,
                'staff_note' => $this->clean($note),
                'started_at' => $status === SupportStatus::Processing ? ($request->started_at ?? now()) : $request->started_at,
                'updated_by_staff_id' => $by->staff_id,
            ]);
            $this->attachResult($request, $file);
            $request->save();

            return $request;
        });
    }

    /**
     * "Konfirmasi selesai & kirim hasil": the result goes back to the referring doctor.
     * A result document is required; it never becomes a prescription by itself.
     */
    public function completeSupport(SupportRequest $request, Staff $by, ?string $note, ?UploadedFile $file): SupportRequest
    {
        return DB::transaction(function () use ($request, $by, $note, $file) {
            $request = $this->openSupport($request);
            $this->attachResult($request, $file);

            if (! $request->result_path) {
                throw new QueueRuleException('Lampirkan hasil pemeriksaan sebelum mengirim ke dokter.');
            }

            $request->fill([
                'status' => SupportStatus::Done,
                'staff_note' => $this->clean($note) ?? $request->staff_note,
                'started_at' => $request->started_at ?? now(),
                'completed_at' => now(),
                'updated_by_staff_id' => $by->staff_id,
            ])->save();

            $queue = $request->queue;
            $this->stage($queue, JourneyStage::Laboratory, StageStatus::Done, $by);
            $this->notify($queue, 'support_done', "Hasil {$request->unitLabel()} sudah dikirim ke dokter.", 'Tunggu panggilan dokter untuk tindak lanjut.');

            return $request;
        });
    }

    /**
     * "Konfirmasi & kirim resep" (frames 31–32): the doctor confirms the examination. With
     * prescription items the visit moves to the pharmacy (a returned prescription is reopened);
     * without, the visit is finished. Support services must have sent their result first.
     */
    public function confirmExamination(Queue $queue, Staff $doctor): Queue
    {
        $queue = DB::transaction(function () use ($queue, $doctor) {
            $queue = Queue::whereKey($queue->queue_id)->lockForUpdate()
                ->with(['examination.items', 'supportRequest', 'pharmacyOrder'])->firstOrFail();
            $this->ensureOwnDoctor($queue, $doctor);
            $exam = $queue->examination;

            if (! $this->examinationOpen($queue)) {
                throw new QueueRuleException('Pemeriksaan ini sudah dikonfirmasi.');
            }
            if (! $exam || ! $exam->notes) {
                throw new QueueRuleException('Tuliskan catatan pemeriksaan sebelum mengonfirmasi.');
            }
            if ($queue->queue_status === QueueStatus::Laboratory && $queue->supportRequest?->status !== SupportStatus::Done) {
                throw new QueueRuleException('Tunggu hasil penunjang sebelum mengonfirmasi pemeriksaan.');
            }

            $prescribed = $exam->items->isNotEmpty();
            $order = $queue->pharmacyOrder;

            if ($order?->prescription_status === PrescriptionStatus::Returned) {
                if (! $prescribed) {
                    throw new QueueRuleException('Resep yang dikembalikan farmasi harus berisi obat.');
                }
                $exam->update(['confirmed_at' => now(), 'confirmed_by_staff_id' => $doctor->staff_id]);
                $order->update(['prescription_status' => PrescriptionStatus::Received, 'received_at' => now(), 'updated_by_staff_id' => $doctor->staff_id]);
                $this->notify($queue, 'pharmacy', "Resep diperbarui dokter. Nomor pengambilan {$order->pickupNumber()}.", config('hytrack.rooms.pharmacy'));

                return $queue;
            }

            $exam->update(['confirmed_at' => now(), 'confirmed_by_staff_id' => $doctor->staff_id]);

            return $this->transition($queue, $prescribed ? QueueStatus::Pharmacy : QueueStatus::Completed, function (Queue $queue) use ($doctor, $prescribed) {
                if ($queue->queue_status === QueueStatus::Serving) {
                    $this->stage($queue, JourneyStage::Consultation, StageStatus::Done, $doctor);
                    $this->stage($queue, JourneyStage::Laboratory, StageStatus::Skipped, $doctor);
                }

                if ($prescribed) {
                    $this->stage($queue, JourneyStage::Pharmacy, StageStatus::Active, $doctor);
                    $order = PharmacyOrder::create([
                        'queue_id' => $queue->queue_id,
                        'updated_by_staff_id' => $doctor->staff_id,
                        'prescription_status' => PrescriptionStatus::Received,
                        'received_at' => now(),
                    ]);
                    $this->notify($queue, 'pharmacy', "Resep dikirim ke farmasi. Nomor pengambilan {$order->pickupNumber()}.", config('hytrack.rooms.pharmacy'));
                } else {
                    $this->stage($queue, JourneyStage::Pharmacy, StageStatus::Skipped, $doctor);
                    $this->endCompanions($queue);
                    $this->notify($queue, 'completed', 'Pemeriksaan selesai tanpa resep. Semoga lekas sehat.');
                }
            });
        });

        $this->estimator->refresh($queue->doctor_id, $queue->service_date);

        return $queue->refresh();
    }

    private function ensureOwnDoctor(Queue $queue, Staff $doctor): void
    {
        if ($doctor->doctor_id === null || $doctor->doctor_id !== $queue->doctor_id) {
            throw new AuthorizationException('Hanya dokter pemeriksa yang dapat mengubah pemeriksaan ini.');
        }
    }

    private function openSupport(SupportRequest $request): SupportRequest
    {
        $request = SupportRequest::whereKey($request->support_request_id)->lockForUpdate()->firstOrFail();

        if ($request->status === SupportStatus::Done) {
            throw new QueueRuleException("Hasil {$request->number()} sudah dikirim ke dokter.");
        }

        return $request;
    }

    /** Stores the result on the private disk; a new file replaces the previous one. */
    private function attachResult(SupportRequest $request, ?UploadedFile $file): void
    {
        if (! $file) {
            return;
        }
        $diskName = config('hytrack.support_result_disk');
        $path = $file->store('support-results', $diskName);

        if ($path === false) {
            throw new QueueRuleException('Hasil pemeriksaan gagal disimpan. Coba lagi.');
        }
        if ($request->result_path) {
            Storage::disk($diskName)->delete($request->result_path);
        }
        $request->fill([
            'result_path' => $path,
            'result_name' => mb_substr($file->getClientOriginalName(), 0, 150),
            'result_mime' => $file->getMimeType(),
        ]);
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Cancellation. The row is kept (never deleted) with when, by whom and why.
     * Patients may cancel before check-in; staff ($by) may also cancel a checked-in patient
     * who has not been called yet. $force is for the demo reset only.
     *
     * Quota rule: cancelled tickets no longer count against the doctor's daily quota, so the
     * slot opens again. They keep their number, so numbering never repeats.
     */
    public function cancel(Queue $queue, bool $force = false, ?Staff $by = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($queue, $force, $by, $reason) {
            $queue = Queue::whereKey($queue->queue_id)->lockForUpdate()->with('journeys')->firstOrFail();
            $status = $queue->queue_status;

            if ($by) {
                if (! in_array($status, [QueueStatus::Booked, QueueStatus::Waiting], true)) {
                    throw new QueueRuleException(match ($status) {
                        QueueStatus::Cancelled => 'Booking ini sudah dibatalkan.',
                        QueueStatus::Serving, QueueStatus::Laboratory, QueueStatus::Pharmacy => 'Pasien sudah dilayani; booking tidak dapat dibatalkan.',
                        default => 'Booking yang sudah selesai tidak dapat dibatalkan.',
                    });
                }
                if ($queue->service_date < HospitalTime::today()) {
                    throw new QueueRuleException('Booking tanggal yang sudah lewat tidak dapat dibatalkan.');
                }
            } elseif (! $status->canBecome(QueueStatus::Cancelled) && ! ($force && $status->isActive())) {
                throw new QueueRuleException('Kunjungan hanya dapat dibatalkan sebelum check-in.');
            }

            $queue->update([
                'queue_status' => QueueStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by_staff_id' => $by?->staff_id,
                'cancel_reason' => $reason !== null ? trim($reason) : ($by ? null : 'Dibatalkan oleh pasien'),
            ]);
            // Stages that never happened are closed as skipped; finished ones keep their record.
            $queue->journeys()->whereIn('stage_status', [StageStatus::Pending, StageStatus::Active])
                ->update(['stage_status' => StageStatus::Skipped, 'updated_by_staff_id' => $by?->staff_id]);
            $this->endCompanions($queue);

            if ($by) {
                $this->notify($queue, 'cancelled', "Booking {$queue->queue_number} dibatalkan petugas.", $queue->cancel_reason ? "Alasan: {$queue->cancel_reason}" : null);
            }
        });

        $this->estimator->refresh($queue->doctor_id, $queue->service_date);
    }

    /** The visit is over: every invitation for it ends, accepted or not. */
    public function endCompanions(Queue $queue): void
    {
        $queue->companionShares()->whereIn('status', InvitationStatus::live())
            ->update(['status' => InvitationStatus::Expired]);
    }

    /** @param Closure(Queue): void $apply */
    private function transition(Queue $queue, QueueStatus $to, Closure $apply): Queue
    {
        return DB::transaction(function () use ($queue, $to, $apply) {
            $queue = Queue::whereKey($queue->queue_id)->lockForUpdate()->with('journeys')->firstOrFail();

            if (! $queue->queue_status->canBecome($to)) {
                throw new QueueRuleException('Tahap kunjungan tidak dapat dilewati atau diulang.');
            }

            $apply($queue);
            $queue->queue_status = $to;
            $queue->save();

            return $queue;
        });
    }

    private function stageRow(JourneyStage $stage, ?string $room, string $instruction): array
    {
        return [
            'stage_name' => $stage->label(),
            'stage_order' => $stage->value,
            'room_location' => $room,
            'stage_status' => StageStatus::Pending,
            'next_step_instruction' => $instruction,
        ];
    }

    private function stage(Queue $queue, JourneyStage $stage, StageStatus $status, ?Staff $by): void
    {
        $row = $queue->journeys()->where('stage_order', $stage->value)->firstOrFail();
        $row->stage_status = $status;
        $row->updated_by_staff_id = $by?->staff_id;
        $row->started_at ??= now();

        if ($status === StageStatus::Done) {
            $row->completed_at = now();
        }

        $row->save();
    }

    private function notify(Queue $queue, string $trigger, string $message, ?string $action = null): void
    {
        $queue->notifications()->create([
            'recipient_patient_id' => $queue->patient_id,
            'trigger_type' => $trigger,
            'message_body' => $message,
            'action_instruction' => $action,
            'sent_at' => now(),
        ]);
    }

    /** Smart Notification: warn the patient who is now first in line, once. */
    private function notifyUpNext(int $doctorId, string $date): void
    {
        $first = Queue::forDoctorOn($doctorId, $date)
            ->where('queue_status', QueueStatus::Waiting)
            ->orderBy('checkin_at')->orderBy('queue_id')
            ->first();

        if ($first && ! $first->notifications()->where('trigger_type', 'up_next')->exists()) {
            $this->notify($first, 'up_next', 'Giliran Anda berikutnya.', 'Tetap di dekat ruang pemeriksaan.');
        }
    }
}

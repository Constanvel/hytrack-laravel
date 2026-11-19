<?php

namespace App\Services;

use App\Enums\InvitationStatus;
use App\Enums\JourneyStage;
use App\Enums\PrescriptionStatus;
use App\Enums\QueueStatus;
use App\Enums\StageStatus;
use App\Exceptions\QueueRuleException;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PharmacyOrder;
use App\Models\Queue;
use App\Models\Staff;
use App\Support\HospitalTime;
use Closure;
use Illuminate\Support\Facades\DB;

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

    /** Move a prescription one step forward; handing over the medicine completes the visit. */
    public function advancePharmacy(PharmacyOrder $order, ?Staff $by = null): PharmacyOrder
    {
        return DB::transaction(function () use ($order, $by) {
            $order = PharmacyOrder::whereKey($order->pharmacy_order_id)->lockForUpdate()->firstOrFail();
            $next = $order->prescription_status->next();

            if (! $next) {
                throw new QueueRuleException('Obat sudah diserahkan.');
            }

            $order->fill(['prescription_status' => $next, 'updated_by_staff_id' => $by?->staff_id]);

            match ($next) {
                PrescriptionStatus::Checking => $order->prepared_at = now(),
                PrescriptionStatus::Ready => $order->fill(['verified_at' => now(), 'ready_at' => now()]),
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

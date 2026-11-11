<?php

namespace App\Services;

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

    public function book(Patient $patient, Doctor $doctor, string $date): Queue
    {
        $queue = DB::transaction(function () use ($patient, $doctor, $date) {
            // Serialises bookings per doctor so two patients cannot get the same number.
            $doctor = Doctor::whereKey($doctor->doctor_id)->lockForUpdate()->with('polyclinic')->firstOrFail();

            if (! in_array($date, $this->bookingDates(), true)) {
                throw new QueueRuleException('Tanggal kunjungan tidak tersedia.');
            }
            if ($doctor->availability_status === Doctor::OFF) {
                throw new QueueRuleException('Dokter tidak praktik pada jadwal ini.');
            }
            if ($patient->queues()->active()->exists()) {
                throw new QueueRuleException('Anda sudah memiliki satu kunjungan aktif.');
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

    /** Arrival confirmed, by staff or (demo only) by the patient's own simulation. */
    public function checkIn(Queue $queue, ?Staff $by = null): Queue
    {
        $queue = $this->transition($queue, QueueStatus::Waiting, function (Queue $queue) use ($by) {
            if ($queue->service_date !== HospitalTime::today()) {
                throw new QueueRuleException('Check-in hanya dapat dilakukan pada tanggal layanan.');
            }

            $queue->checkin_at = now();
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

    /** A patient is only marked absent after at least one repeat call. */
    public function markNoShow(Queue $queue, ?Staff $by = null): Queue
    {
        $queue = $this->transition($queue, QueueStatus::NoShow, function (Queue $queue) use ($by) {
            if ($queue->called_count < 2) {
                throw new QueueRuleException('Tandai tidak hadir hanya setelah panggilan ulang.');
            }

            $this->stage($queue, JourneyStage::Consultation, StageStatus::Skipped, $by);
            $this->revokeCompanions($queue);
            $this->notify($queue, 'no_show', 'Anda tidak hadir saat dipanggil.', 'Hubungi petugas di meja check-in.');
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
                    $this->revokeCompanions($queue);
                    $this->notify($queue, 'completed', 'Kunjungan selesai. Semoga lekas sehat.');
                });
            }

            return $order;
        });
    }

    /** Patients may cancel before check-in. $force is for the demo reset only. */
    public function cancel(Queue $queue, bool $force = false): void
    {
        DB::transaction(function () use ($queue, $force) {
            $queue = Queue::whereKey($queue->queue_id)->lockForUpdate()->firstOrFail();

            if (! $queue->queue_status->canBecome(QueueStatus::Cancelled) && ! ($force && $queue->queue_status->isActive())) {
                throw new QueueRuleException('Kunjungan hanya dapat dibatalkan sebelum check-in.');
            }

            $queue->update(['queue_status' => QueueStatus::Cancelled]);
            $this->revokeCompanions($queue);
        });

        $this->estimator->refresh($queue->doctor_id, $queue->service_date);
    }

    public function revokeCompanions(Queue $queue): void
    {
        $queue->companionShares()->where('is_active', true)->update(['is_active' => false]);
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

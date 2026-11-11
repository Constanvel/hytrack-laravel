<?php

namespace Database\Seeders;

use App\Enums\JourneyStage;
use App\Enums\PrescriptionStatus;
use App\Enums\QueueStatus;
use App\Enums\StageStatus;
use App\Enums\UserRole;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PharmacyOrder;
use App\Models\Polyclinic;
use App\Models\Queue;
use App\Models\Staff;
use App\Models\User;
use App\Services\WaitEstimator;
use App\Support\HospitalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Demonstration data only: sample clinics, three demo accounts and invented visits so
 * the dashboards have something to show. None of it comes from a hospital system.
 * Visits are created relative to "today", so re-seed on the day of a demo.
 */
class DatabaseSeeder extends Seeder
{
    private const CONSULT_MINUTES = 5;

    private Staff $clerk;

    /** @var Collection<int, Patient> */
    private Collection $patients;

    /** Next unused patient for today's sessions; the first 28 belong to dr. Rina's morning. */
    private int $cursor = 28;

    public function run(): void
    {
        DB::transaction(function () {
            $this->accounts();
            [$rina, $budi, $maya, $andi] = $this->clinics();

            $today = HospitalTime::today();
            $yesterday = HospitalTime::now()->subDay()->toDateString();

            $this->history($yesterday, [[$rina, 26, 6], [$budi, 18, 6], [$maya, 16, 0], [$andi, 14, -6]]);
            $this->liveMorning($rina, $today);
            $this->today($budi, $today, booked: 13);
            $this->today($maya, $today, examined: 5, serving: true, waiting: 2);
            $this->today($andi, $today, booked: 4);

            foreach ([$rina, $budi, $maya, $andi] as $doctor) {
                app(WaitEstimator::class)->refresh($doctor->doctor_id, $today);
            }
        });
    }

    private function accounts(): void
    {
        $password = Hash::make(config('hytrack.demo_password'));
        $account = fn (string $username, UserRole $role) => User::create([
            'username' => $username,
            'password_hash' => $password,
            'role' => $role,
        ]);

        Patient::create([
            'user_id' => $account('siti', UserRole::Patient)->user_id,
            'full_name' => 'Siti Aminah',
            'phone_number' => '+62 812-5550-0842',
        ]);
        $this->clerk = Staff::create([
            'user_id' => $account('dina', UserRole::Staff)->user_id,
            'full_name' => 'Dina',
            'work_unit' => 'Poli Penyakit Dalam',
        ]);
        Staff::create([
            'user_id' => $account('manajer', UserRole::Manager)->user_id,
            'full_name' => 'Rahma',
            'work_unit' => 'Manajemen operasional',
        ]);

        // Other patients exist only to populate the queues; nobody can sign in as them.
        $locked = Hash::make(Str::random(40));
        $names = ['Hendra Wijaya', 'Agus Setiawan', 'Rudi Hartono', 'Ratna Sari', 'Eko Prasetyo', 'Dewi Lestari'];
        fake()->seed(842);

        $this->patients = collect(range(1, 74))->map(fn (int $number) => Patient::create([
            'user_id' => User::create([
                'username' => sprintf('pasien%03d', $number),
                'password_hash' => $locked,
                'role' => UserRole::Patient,
            ])->user_id,
            'full_name' => $names[$number - 1] ?? fake()->firstName().' '.fake()->lastName(),
        ]));
    }

    /** @return array{Doctor, Doctor, Doctor, Doctor} */
    private function clinics(): array
    {
        $clinic = fn (string $name, ?string $room) => Polyclinic::create(['polyclinic_name' => $name, 'room_location' => $room]);
        $doctor = fn (Polyclinic $clinic, string $name, string $start, string $end) => Doctor::create([
            'polyclinic_id' => $clinic->polyclinic_id,
            'doctor_name' => $name,
            'schedule_start' => $start,
            'schedule_end' => $end,
            'availability_status' => Doctor::AVAILABLE,
        ])->setRelation('polyclinic', $clinic);

        $internal = $clinic('Penyakit Dalam', 'Ruang A-204 · Gedung A, lantai 2');
        $heart = $clinic('Jantung', 'Ruang A-210 · Gedung A, lantai 2');
        $children = $clinic('Anak', 'Ruang B-105 · Gedung B, lantai 1');
        $clinic('Saraf', null); // no doctor yet: shows the "schedule not available" state

        return [
            $doctor($internal, 'dr. Rina Kusuma, Sp.PD', '09:00:00', '12:00:00'),
            $doctor($internal, 'dr. Budi Santoso, Sp.PD', '13:00:00', '16:00:00'),
            $doctor($heart, 'dr. Maya Pratiwi, Sp.JP', '08:00:00', '11:00:00'),
            $doctor($children, 'dr. Andi Wirawan, Sp.A', '10:00:00', '13:00:00'),
        ];
    }

    /**
     * The scene of the original design: A-023 with the doctor, three patients waiting,
     * so the next ticket issued is A-027 with three patients ahead.
     */
    private function liveMorning(Doctor $rina, string $today): void
    {
        $now = CarbonImmutable::now();
        $waits = [12, 18, 24, 31, 36, 28, 22];
        $inPharmacy = [15 => PrescriptionStatus::Ready, 16 => PrescriptionStatus::Checking, 17 => PrescriptionStatus::Preparing, 19 => PrescriptionStatus::Received];

        foreach (range(1, 22) as $sequence) {
            $called = $now->subMinutes(8 + (23 - $sequence) * self::CONSULT_MINUTES);
            $status = match (true) {
                in_array($sequence, [7, 13, 18], true) => QueueStatus::NoShow,
                isset($inPharmacy[$sequence]) => QueueStatus::Pharmacy,
                $sequence >= 20 => QueueStatus::Laboratory,
                default => QueueStatus::Completed,
            };

            $this->visit($this->patients[5 + $sequence], $rina, $today, $sequence, $status,
                $called->subMinutes($waits[$sequence % 7]), $called, $inPharmacy[$sequence] ?? null);
        }

        $this->visit($this->patients[0], $rina, $today, 23, QueueStatus::Serving, $now->subMinutes(45), $now->subMinutes(8));
        foreach ([24 => 40, 25 => 36, 26 => 32] as $sequence => $minutesAgo) {
            $this->visit($this->patients[$sequence - 23], $rina, $today, $sequence, QueueStatus::Waiting, $now->subMinutes($minutesAgo));
        }
    }

    /** A simpler session: some patients already examined, one with the doctor, the rest in line. */
    private function today(Doctor $doctor, string $today, int $booked = 0, int $examined = 0, bool $serving = false, int $waiting = 0): void
    {
        $now = CarbonImmutable::now();
        $sequence = 0;
        $next = fn () => $this->patients[$this->cursor++];

        for ($index = 1; $index <= $examined; $index++) {
            $called = $now->subMinutes(70 + ($examined - $index) * self::CONSULT_MINUTES);
            $this->visit($next(), $doctor, $today, ++$sequence, QueueStatus::Completed, $called->subMinutes(20), $called);
        }
        if ($serving) {
            $this->visit($next(), $doctor, $today, ++$sequence, QueueStatus::Serving, $now->subMinutes(30), $now->subMinutes(3));
        }
        for ($index = 1; $index <= $waiting; $index++) {
            $this->visit($next(), $doctor, $today, ++$sequence, QueueStatus::Waiting, $now->subMinutes(25 - $index));
        }
        for ($index = 1; $index <= $booked; $index++) {
            $this->visit($next(), $doctor, $today, ++$sequence, QueueStatus::Booked);
        }
    }

    /**
     * A finished service day, with waits that peak mid-morning.
     *
     * @param  list<array{Doctor, int, int}>  $sessions  doctor, number of visits, minutes added to every wait
     */
    private function history(string $date, array $sessions): void
    {
        $waitByHour = [7 => 18, 8 => 24, 9 => 32, 10 => 48, 11 => 42, 12 => 35, 13 => 28, 14 => 21, 15 => 17];
        $offset = 0;

        foreach ($sessions as [$doctor, $visits, $extraWait]) {
            $starts = HospitalTime::at($date, $doctor->schedule_start);
            $opens = $starts->subMinutes(40);

            foreach (range(1, $visits) as $sequence) {
                $checkIn = $opens->addMinutes(($sequence - 1) * 8);
                $hour = (int) HospitalTime::local($checkIn)->format('G');
                // Nobody is called before the doctor's practice starts.
                $called = $checkIn->addMinutes(max(5, ($waitByHour[$hour] ?? 20) + $extraWait + $sequence % 5 - 2))->max($starts);

                $this->visit($this->patients[$offset++], $doctor, $date, $sequence,
                    $sequence % 11 === 0 ? QueueStatus::NoShow : QueueStatus::Completed, $checkIn, $called);
            }
        }
    }

    /** One invented visit with stage timestamps consistent with its status. */
    private function visit(Patient $patient, Doctor $doctor, string $date, int $sequence, QueueStatus $status, ?CarbonImmutable $checkIn = null, ?CarbonImmutable $called = null, ?PrescriptionStatus $medicine = null): void
    {
        $deadline = HospitalTime::at($date, $doctor->schedule_start)->subMinutes(config('hytrack.check_in_lead_minutes'));
        $examined = $called?->addMinutes(self::CONSULT_MINUTES);
        $labDone = $examined?->addMinutes(18);
        $ready = $labDone?->addMinutes(22);
        $pastDoctor = in_array($status, [QueueStatus::Laboratory, QueueStatus::Pharmacy, QueueStatus::Completed], true);
        $pastLab = in_array($status, [QueueStatus::Pharmacy, QueueStatus::Completed], true);
        $done = $status === QueueStatus::Completed;

        $queue = Queue::create([
            'patient_id' => $patient->patient_id,
            'doctor_id' => $doctor->doctor_id,
            'polyclinic_id' => $doctor->polyclinic_id,
            'service_date' => $date,
            'queue_number' => sprintf('%s-%03d', $doctor->ticketPrefix(), $sequence),
            'queue_status' => $status,
            'checkin_deadline' => $deadline,
            'recommended_departure_time' => $deadline->subMinutes(config('hytrack.travel_minutes')),
            'checkin_at' => $checkIn,
            'called_count' => $status === QueueStatus::NoShow ? 2 : ($called ? 1 : 0),
        ]);

        $rooms = config('hytrack.rooms');
        $stage = fn (JourneyStage $stage, ?string $room, StageStatus $state, ?CarbonImmutable $from, ?CarbonImmutable $to) => [
            'stage_name' => $stage->label(),
            'stage_order' => $stage->value,
            'room_location' => $room,
            'stage_status' => $state,
            'started_at' => $from,
            'completed_at' => $to,
            'updated_by_staff_id' => $from ? $this->clerk->staff_id : null,
        ];
        $state = fn (bool $started, bool $finished) => $finished ? StageStatus::Done : ($started ? StageStatus::Active : StageStatus::Pending);

        $queue->journeys()->createMany([
            $stage(JourneyStage::CheckIn, $rooms['check_in'], $state((bool) $checkIn, (bool) $checkIn), $checkIn, $checkIn),
            $stage(JourneyStage::Consultation, $doctor->polyclinic->room_location,
                $status === QueueStatus::NoShow ? StageStatus::Skipped : $state((bool) $called, $pastDoctor),
                $called, $pastDoctor ? $examined : null),
            $stage(JourneyStage::Laboratory, $rooms['laboratory'], $state($pastDoctor, $pastLab), $pastDoctor ? $examined : null, $pastLab ? $labDone : null),
            $stage(JourneyStage::Pharmacy, $rooms['pharmacy'], $state($pastLab, $done), $pastLab ? $labDone : null, $done ? $ready->addMinutes(5) : null),
        ]);

        if ($pastLab) {
            $medicine ??= PrescriptionStatus::Completed;
            $reached = fn (PrescriptionStatus ...$steps) => in_array($medicine, $steps, true);

            PharmacyOrder::create([
                'queue_id' => $queue->queue_id,
                'updated_by_staff_id' => $this->clerk->staff_id,
                'prescription_status' => $medicine,
                'received_at' => $labDone,
                'prepared_at' => $reached(PrescriptionStatus::Checking, PrescriptionStatus::Ready, PrescriptionStatus::Completed) ? $labDone->addMinutes(12) : null,
                'verified_at' => $reached(PrescriptionStatus::Ready, PrescriptionStatus::Completed) ? $ready : null,
                'ready_at' => $reached(PrescriptionStatus::Ready, PrescriptionStatus::Completed) ? $ready : null,
                'completed_at' => $done ? $ready->addMinutes(5) : null,
            ]);
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Doctor;
use App\Models\Polyclinic;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Minimum data for a new (production) database, instead of the demo seeder: polyclinics
 * and doctor schedules when none exist yet, and one staff account whose password is typed
 * by the operator (never stored in the code, the shell history or the environment).
 * Safe to run again: existing clinics, doctors and accounts are left untouched.
 */
class SetupHytrack extends Command
{
    protected $signature = 'hytrack:setup
        {--staff= : Nama pengguna akun petugas yang dibuat (kosongkan untuk melewati)}
        {--name= : Nama lengkap petugas}';

    protected $description = 'Isi data awal HyTrack (poliklinik, dokter) dan buat akun petugas tanpa sandi demo';

    public function handle(): int
    {
        $this->line('Database: '.DB::connection()->getDriverName().' · '.DB::connection()->getDatabaseName());

        $this->clinics();
        $username = $this->option('staff') ?? text('Nama pengguna petugas (kosongkan untuk melewati)', default: '');

        return $username === '' ? self::SUCCESS : $this->staff(strtolower(trim($username)));
    }

    private function clinics(): void
    {
        if (Polyclinic::exists()) {
            $this->info('Poliklinik sudah ada ('.Polyclinic::count().'), tidak diubah.');

            return;
        }

        DB::transaction(function () {
            $schedule = [
                ['Penyakit Dalam', 'Ruang A-204 · Gedung A, lantai 2', [
                    ['dr. Rina Kusuma, Sp.PD', '09:00', '12:00'],
                    ['dr. Budi Santoso, Sp.PD', '13:00', '16:00'],
                ]],
                ['Jantung', 'Ruang A-210 · Gedung A, lantai 2', [['dr. Maya Pratiwi, Sp.JP', '08:00', '11:00']]],
                ['Anak', 'Ruang B-105 · Gedung B, lantai 1', [['dr. Andi Wirawan, Sp.A', '10:00', '13:00']]],
            ];
            foreach ($schedule as [$name, $room, $doctors]) {
                $clinic = Polyclinic::create(['polyclinic_name' => $name, 'room_location' => $room]);
                foreach ($doctors as [$doctor, $start, $end]) {
                    Doctor::create([
                        'polyclinic_id' => $clinic->polyclinic_id,
                        'doctor_name' => $doctor,
                        'schedule_start' => "$start:00",
                        'schedule_end' => "$end:00",
                        'availability_status' => Doctor::AVAILABLE,
                    ]);
                }
            }
        });
        $this->info('Dibuat: '.Polyclinic::count().' poliklinik, '.Doctor::count().' dokter.');
    }

    private function staff(string $username): int
    {
        if (! preg_match('/^[a-z0-9._-]{3,50}$/', $username)) {
            $this->error('Nama pengguna 3–50 karakter: huruf kecil, angka, titik, garis bawah, atau tanda hubung.');

            return self::FAILURE;
        }
        if (User::where('username', $username)->exists()) {
            $this->info("Akun {$username} sudah ada, tidak diubah.");

            return self::SUCCESS;
        }

        $name = $this->option('name') ?? text('Nama lengkap petugas', required: true);
        $secret = password('Kata sandi petugas (min. 10 karakter, tidak ditampilkan)', required: true,
            validate: fn (string $value) => mb_strlen($value) < 10 ? 'Minimal 10 karakter.' : null);
        if (password('Ulangi kata sandi', required: true) !== $secret) {
            $this->error('Kata sandi tidak sama. Tidak ada akun yang dibuat.');

            return self::FAILURE;
        }

        DB::transaction(fn () => Staff::create([
            'user_id' => User::create([
                'username' => $username,
                'password_hash' => Hash::make($secret),
                'role' => UserRole::Staff,
            ])->user_id,
            'full_name' => trim($name),
            'work_unit' => 'Pendaftaran rawat jalan',
        ]));
        $this->info("Akun petugas {$username} dibuat.");

        return self::SUCCESS;
    }
}

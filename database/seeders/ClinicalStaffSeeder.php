<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Doctor;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Figma 47:2 rooms: dr. Rina's doctor account, a laboratory analyst and a pharmacist.
 * Run on its own it adds them to a demo database seeded before they existed, so a deployed
 * demo gets them without re-seeding. Existing usernames are never touched.
 */
class ClinicalStaffSeeder extends Seeder
{
    public function run(): void
    {
        $rina = Doctor::with('polyclinic')->where('doctor_name', 'dr. Rina Kusuma, Sp.PD')->first();

        // Only a demo database (it has the dina desk account) gets accounts with the demo password.
        if ($rina && User::where('username', 'dina')->exists()) {
            $this->accounts($rina);
        }
    }

    /** @return array{?Staff, ?Staff, ?Staff} null where the username is already taken */
    public function accounts(Doctor $rina): array
    {
        $password = null;
        $staff = function (string $username, string $name, string $unit, ?int $doctorId = null) use (&$password) {
            if (User::where('username', $username)->exists()) {
                return null;
            }
            $password ??= Hash::make(config('hytrack.demo_password'));

            return DB::transaction(fn () => Staff::create([
                'user_id' => User::create(['username' => $username, 'password_hash' => $password, 'role' => UserRole::Staff])->user_id,
                'full_name' => $name,
                'work_unit' => $unit,
                'doctor_id' => $doctorId,
            ]));
        };

        return [
            $staff('rina', $rina->doctor_name, $rina->polyclinic->polyclinic_name, $rina->doctor_id),
            $staff('rani', 'Rani', 'Laboratorium'),
            $staff('dinda', 'Apt. Dinda', 'Farmasi'),
        ];
    }
}

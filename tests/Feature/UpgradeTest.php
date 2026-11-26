<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Doctor;
use App\Models\Polyclinic;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** hytrack:upgrade, which Vercel runs on each new instance instead of a manual migrate. */
class UpgradeTest extends TestCase
{
    use RefreshDatabase;

    private function rina(): Doctor
    {
        $clinic = Polyclinic::create(['polyclinic_name' => 'Penyakit Dalam', 'room_location' => 'Ruang A-204']);

        return Doctor::create([
            'polyclinic_id' => $clinic->polyclinic_id,
            'doctor_name' => 'dr. Rina Kusuma, Sp.PD',
            'schedule_start' => '09:00:00',
            'schedule_end' => '12:00:00',
            'availability_status' => Doctor::AVAILABLE,
        ]);
    }

    public function test_an_older_demo_database_gets_the_room_accounts_once(): void
    {
        $rina = $this->rina();
        User::create(['username' => 'dina', 'password_hash' => Hash::make('x'), 'role' => UserRole::Staff]);
        // Somebody already holds "rani": that account must stay as it is.
        $rani = User::create(['username' => 'rani', 'password_hash' => 'kept', 'role' => UserRole::Patient]);

        $this->artisan('hytrack:upgrade')->assertSuccessful();
        $this->artisan('hytrack:upgrade')->assertSuccessful();

        $doctor = Staff::whereRelation('user', 'username', 'rina')->sole();
        $this->assertSame($rina->doctor_id, $doctor->doctor_id);
        $this->assertSame(Staff::DOCTOR, $doctor->room());
        $this->assertSame(Staff::PHARMACY, Staff::whereRelation('user', 'username', 'dinda')->sole()->room());
        $this->assertTrue(Hash::check(config('hytrack.demo_password'), $doctor->user->password_hash));
        $this->assertSame($rani->password_hash, $rani->fresh()->password_hash);
        $this->assertSame(0, Staff::where('user_id', $rani->user_id)->count());
        $this->assertSame(4, User::count());
    }

    public function test_a_real_database_gets_no_demo_password_accounts(): void
    {
        $this->rina();

        $this->artisan('hytrack:upgrade')->assertSuccessful();

        $this->assertSame(0, User::count());
    }
}

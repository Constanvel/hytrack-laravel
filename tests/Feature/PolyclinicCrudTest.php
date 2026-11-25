<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Polyclinic;
use App\Models\Staff;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PolyclinicCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_create_read_update_and_delete_a_polyclinic(): void
    {
        $staff = Staff::factory()->create();
        $this->actingAs($staff->user)->from('/petugas');

        $this->post('/petugas/poliklinik', ['name' => '  Mata Uji ', 'room' => 'Ruang C-101'])->assertRedirect('/petugas');
        $clinic = Polyclinic::where('polyclinic_name', 'Mata Uji')->firstOrFail();
        $this->assertSame('Ruang C-101', $clinic->room_location);

        $this->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('clinicAdmin.0.name', 'Mata Uji')
            ->where('clinicAdmin.0.doctors', 0));

        $this->put("/petugas/poliklinik/{$clinic->polyclinic_id}", ['name' => 'Mata Uji 2', 'room' => ''])->assertRedirect();
        $clinic->refresh();
        $this->assertSame('Mata Uji 2', $clinic->polyclinic_name);
        $this->assertNull($clinic->room_location);

        $this->delete("/petugas/poliklinik/{$clinic->polyclinic_id}")->assertRedirect();
        $this->assertSoftDeleted($clinic);
        $this->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page->where('clinicAdmin', []));
    }

    public function test_names_are_validated_and_an_archived_name_can_be_used_again(): void
    {
        $staff = Staff::factory()->create();
        $doctor = Doctor::factory()->create();
        $clinic = $doctor->polyclinic;
        $this->actingAs($staff->user)->from('/petugas');

        $this->post('/petugas/poliklinik', ['name' => 'ab'])->assertSessionHasErrors('name');
        $this->post('/petugas/poliklinik', ['name' => $clinic->polyclinic_name])->assertSessionHasErrors('name');
        // Saving a clinic under its own name is not a duplicate.
        $this->put("/petugas/poliklinik/{$clinic->polyclinic_id}", ['name' => $clinic->polyclinic_name])->assertSessionHasNoErrors();

        // A clinic with doctors can be deleted too; the doctor keeps pointing at it.
        $this->delete("/petugas/poliklinik/{$clinic->polyclinic_id}")->assertRedirect();
        $this->assertSoftDeleted($clinic);
        $this->assertSame($clinic->polyclinic_name, $doctor->fresh()->polyclinic->polyclinic_name);
        $this->post('/petugas/poliklinik', ['name' => $clinic->polyclinic_name])->assertSessionHasNoErrors();
    }

    public function test_deleting_a_clinic_in_use_keeps_its_queues_and_lets_staff_finish_them(): void
    {
        $this->travelTo(Carbon::parse('2026-11-04 08:00', 'Asia/Jakarta'));
        $staff = Staff::factory()->create();
        $doctor = Doctor::factory()->create();
        $clinic = $doctor->polyclinic;
        $patient = Patient::factory()->create();
        $queue = app(QueueService::class)->book($patient, $doctor, '2026-11-04');

        $this->actingAs($staff->user)->from('/petugas')
            ->delete("/petugas/poliklinik/{$clinic->polyclinic_id}")->assertRedirect('/petugas')
            ->assertInertiaFlash('status', "Poliklinik {$clinic->polyclinic_name} dihapus dari daftar. Riwayat antreannya tetap tersimpan. "
                .'1 antrean yang masih berjalan tetap bisa diselesaikan di dashboard.');
        $this->assertSoftDeleted($clinic);
        $this->assertModelExists($queue);
        $this->assertSame($clinic->polyclinic_name, $queue->fresh()->polyclinic->polyclinic_name);

        // Gone from the master list and walk-in booking, still on the live dashboard for its ongoing queue.
        $this->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('clinicAdmin', [])
            ->where('clinics.0.name', "{$clinic->polyclinic_name} (dihapus)"));
        $this->getJson('/petugas/jadwal?tanggal=2026-11-04')->assertJsonPath('clinics', []);

        // Patients no longer see it, and its doctors take no new bookings.
        $other = Patient::factory()->create();
        $this->actingAs($other->user)->get('/poliklinik')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('clinics', []));
        $this->post('/kunjungan', ['doctor_id' => $doctor->doctor_id, 'date' => '2026-11-04']);
        $this->assertSame(0, $other->queues()->count());

        // The patient who already had a ticket still sees where to go.
        $this->actingAs($patient->user)->get('/kunjungan')->assertOk();
    }

    public function test_only_active_staff_manage_polyclinics(): void
    {
        $clinic = Polyclinic::factory()->create();
        $patient = Patient::factory()->create();

        $this->post('/petugas/poliklinik', ['name' => 'Kulit'])->assertRedirect('/masuk');
        $this->actingAs($patient->user)->post('/petugas/poliklinik', ['name' => 'Kulit'])->assertForbidden();
        $this->actingAs($patient->user)->delete("/petugas/poliklinik/{$clinic->polyclinic_id}")->assertForbidden();
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $this->actingAs($manager)->put("/petugas/poliklinik/{$clinic->polyclinic_id}", ['name' => 'Kulit'])->assertForbidden();

        $this->assertModelExists($clinic);
        $this->assertSame(0, Polyclinic::where('polyclinic_name', 'Kulit')->count());
    }

    public function test_setup_adds_reference_data_and_a_staff_account_once(): void
    {
        $this->artisan('hytrack:setup', ['--staff' => 'petugas.rama', '--name' => 'Rama'])
            ->expectsQuestion('Kata sandi petugas (min. 10 karakter, tidak ditampilkan)', 'sandi-rahasia-123')
            ->expectsQuestion('Ulangi kata sandi', 'sandi-rahasia-123')
            ->assertSuccessful();

        $this->assertSame(3, Polyclinic::count());
        $this->assertSame(4, Doctor::count());
        $user = User::where('username', 'petugas.rama')->firstOrFail();
        $this->assertSame(UserRole::Staff, $user->role);
        $this->assertNotSame('sandi-rahasia-123', $user->password_hash);
        $this->assertSame('Rama', $user->staff->full_name);
        $this->assertSame(0, User::where('role', UserRole::Patient)->count()); // no demo accounts

        // Running it again changes nothing.
        $this->artisan('hytrack:setup', ['--staff' => 'petugas.rama'])->assertSuccessful();
        $this->assertSame(3, Polyclinic::count());
        $this->assertSame(1, User::count());

        $this->post('/masuk', ['username' => 'petugas.rama', 'password' => 'sandi-rahasia-123'])->assertRedirect('/petugas');
    }

    public function test_setup_takes_the_password_from_the_environment_without_prompting(): void
    {
        putenv('HYTRACK_STAFF_PASSWORD=pendek');
        try {
            $this->artisan('hytrack:setup', ['--staff' => 'petugas2', '--name' => 'Dua'])->assertFailed();
            $this->assertSame(0, User::count());

            putenv('HYTRACK_STAFF_PASSWORD=sandi-dari-env-123');
            $this->artisan('hytrack:setup', ['--staff' => 'petugas2', '--name' => 'Dua'])->assertSuccessful();
        } finally {
            putenv('HYTRACK_STAFF_PASSWORD');
        }

        $this->post('/masuk', ['username' => 'petugas2', 'password' => 'sandi-dari-env-123'])->assertRedirect('/petugas');
    }
}

<?php

namespace Tests\Feature;

use App\Enums\StageStatus;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\Staff;
use App\Models\User;
use App\Support\CheckInTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Operational mode (HYTRACK_DEMO_CONTROLS=false, see phpunit.xml): a patient account and a
 * staff account, each signing in with its own session, against the test database only.
 */
class OperationalFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'rahasia-pasien-1';

    private const STAFF_PASSWORD = 'rahasia-petugas-1';

    private Doctor $doctor;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        // Doctor practises 09.00–12.00, so the check-in deadline is 08.45.
        $this->travelTo(Carbon::parse('2026-11-04 07:30', 'Asia/Jakarta'));
        $this->doctor = Doctor::factory()->create(['doctor_name' => 'dr. Rina Kusuma, Sp.PD']);
        $this->staff = Staff::factory()->create([
            'full_name' => 'Dina Lestari',
            'user_id' => User::factory()->staff()->create(['username' => 'dina', 'password_hash' => self::STAFF_PASSWORD])->user_id,
        ]);
    }

    public function test_register_logout_login_book_scan_confirm_and_the_record_survives_new_logins(): void
    {
        $this->assertFalse(config('hytrack.demo_controls'));

        // 1. Sign-up: users + patients in one go, password hashed.
        $this->signUp([
            'name' => 'Budi Santoso',
            'phone' => '+62 812-7777-1234',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'terms' => '1',
        ])->assertRedirect('/daftar/selesai');
        $user = User::where('username', '6281277771234')->firstOrFail();
        $this->assertNotSame(self::PASSWORD, $user->password_hash);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password_hash));
        $this->assertSame('Budi Santoso', $user->patient->full_name);

        // 2. Logout, then sign in again with the 08 format of the same number.
        $this->post('/keluar')->assertRedirect('/masuk');
        $this->get('/kunjungan')->assertRedirect('/masuk');
        // Back to the page that asked for a login.
        $this->login('0812-7777-1234', self::PASSWORD)->assertRedirect('/kunjungan');

        // 3. "Ambil antrean" for tomorrow: the ticket exists only after the transaction.
        $this->post('/kunjungan', [
            'doctor_id' => $this->doctor->doctor_id,
            'polyclinic_id' => $this->doctor->polyclinic_id,
            'date' => '2026-11-05',
        ])->assertRedirect('/kunjungan');
        $queue = $user->patient->queues()->firstOrFail();
        $this->assertSame('booked', $queue->queue_status->value);
        $this->assertNotNull($queue->booked_at);
        $this->assertNull($queue->booked_by_staff_id);
        $this->assertSame(4, $queue->journeys()->count());
        $this->props('/kunjungan', fn (AssertableInertia $page) => $page
            ->where('visit.ticket', $queue->queue_number)
            ->where('visit.stage', 'booked')
            ->where('visit.checkInQr', CheckInTicket::payload($queue)));
        $this->props('/riwayat', fn (AssertableInertia $page) => $page
            ->where('items.0.number', $queue->queue_number)
            ->where('items.0.statusLabel', 'Belum check-in'));

        // 4. Staff, in another session, see the booking under its own service date.
        $this->post('/keluar');
        $this->login('dina', self::STAFF_PASSWORD)->assertRedirect('/petugas');
        $this->props('/petugas', fn (AssertableInertia $page) => $page
            ->where('recentBookings.0.number', $queue->queue_number)
            ->where('recentBookings.0.date', '2026-11-05')
            ->where('bookingFilters.date', '2026-11-04')
            ->where('bookings', []));
        $this->props('/petugas?tanggal=2026-11-05', fn (AssertableInertia $page) => $page
            ->where('bookings.0.number', $queue->queue_number)
            ->where('bookings.0.name', 'Budi Santoso')
            ->where('bookings.0.statusLabel', 'Belum check-in')
            ->where('bookings.0.bookedBy', 'Pasien (aplikasi)'));

        // The QR is for tomorrow, so today it is refused.
        $payload = CheckInTicket::payload($queue);
        $this->assertStringStartsWith('Tiket untuk Kamis, 5 November 2026', $this->postAndFlash('/petugas/scan', ['code' => $payload])['error']);

        // 5. Next day: scan, match, confirm.
        $this->travelTo(Carbon::parse('2026-11-05 08:10', 'Asia/Jakarta'));
        $scan = $this->postAndFlash('/petugas/scan', ['code' => $payload])['scan'];
        $this->assertSame(['booked', 'Budi Santoso', $queue->queue_number, 'Penyakit Dalam', 'Kamis, 5 November 2026'],
            [$scan['state'], $scan['name'], $scan['ticket'], $scan['clinic'], $scan['date']]);
        $this->assertSame('booked', $queue->refresh()->queue_status->value);

        $this->assertSame("{$queue->queue_number} berhasil check-in pukul 08.10.", $this->postAndFlash('/petugas/check-in', ['code' => $payload])['status']);
        $queue->refresh();
        $this->assertSame('waiting', $queue->queue_status->value);
        $this->assertSame($this->staff->staff_id, $queue->checkin_by_staff_id);
        $this->assertSame('qr', $queue->checkin_method);
        $this->assertSame(StageStatus::Done, $queue->journeys()->where('stage_order', 1)->value('stage_status'));

        // 6. The same QR again: "Sudah check-in" with the original time, nothing changes.
        $this->travelTo(Carbon::parse('2026-11-05 08:25', 'Asia/Jakarta'));
        $again = $this->postAndFlash('/petugas/scan', ['code' => $payload])['scan'];
        $this->assertSame(['checked_in', '08.10', 'Dina Lestari'], [$again['state'], $again['checkedInAt'], $again['checkedInBy']]);
        $this->assertStringContainsString('sudah check-in pukul 08.10', $this->postAndFlash('/petugas/check-in', ['code' => $payload])['error']);
        $this->assertSame('08:10', $queue->refresh()->checkin_at->setTimezone('Asia/Jakarta')->format('H:i'));

        // 7. Patient screen (the next poll) shows the change; both log out and back in.
        $this->post('/keluar');
        $this->login('+62 812 7777 1234', self::PASSWORD);
        $this->props('/kunjungan', fn (AssertableInertia $page) => $page
            ->where('visit.stage', 'waiting')
            ->where('visit.checkedInAt', '08.10')
            ->has('syncedAt'));
        $this->props('/riwayat', fn (AssertableInertia $page) => $page
            ->where('items.0.statusLabel', 'Sudah check-in')
            ->where('items.0.checkInAt', '5 Nov 2026 · 08.10'));
        $this->post('/keluar');
        $this->login('dina', self::STAFF_PASSWORD);
        $this->props('/petugas?cari='.$queue->queue_number, fn (AssertableInertia $page) => $page
            ->where('bookings.0.checkInAt', '5 Nov 2026 · 08.10')
            ->where('bookings.0.checkInBy', 'Dina Lestari')
            ->where('bookings.0.checkInMethod', 'Pindai QR'));
    }

    public function test_patients_cannot_open_the_dashboard_or_record_attendance(): void
    {
        $queue = $this->bookFor($patient = $this->patient('0812-1111-2222'), '2026-11-04');
        $this->actingAs($patient->user);

        $this->get('/petugas')->assertForbidden();
        $this->get('/petugas/jadwal?tanggal=2026-11-04')->assertForbidden();
        $this->post('/petugas/check-in', ['code' => $queue->checkInCode()])->assertForbidden();
        $this->post("/petugas/antrean/{$queue->queue_id}/tidak-hadir")->assertForbidden();
        $this->post("/petugas/antrean/{$queue->queue_id}/batal", ['reason' => 'coba'])->assertForbidden();
        $this->post("/petugas/dokter/{$this->doctor->doctor_id}/panggil")->assertForbidden();
        // Demo controls are off: the patient's own "simulation" is refused.
        $this->post('/kunjungan/simulasi', ['action' => 'check-in'])->assertForbidden();
        $this->assertSame('booked', $queue->refresh()->queue_status->value);
        $this->assertNull($queue->checkin_at);

        // A staff account without a staff profile may not act either.
        $this->actingAs(User::factory()->staff()->create())->post('/petugas/check-in', ['code' => $queue->checkInCode()])->assertForbidden();
    }

    public function test_one_phone_number_in_any_format_is_one_account(): void
    {
        $this->signUp(['name' => 'A', 'phone' => '0812-3333-4444', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'terms' => '1']);
        $this->post('/keluar');
        $this->post('/daftar', ['name' => 'B', 'phone' => '+62 812 3333 4444', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, 'terms' => '1'])
            ->assertSessionHasErrors(['phone' => 'Nomor ponsel sudah terdaftar. Silakan masuk.']);
        $this->assertSame(1, User::where('username', '6281233334444')->count());
    }

    public function test_a_double_click_issues_one_ticket(): void
    {
        $patient = $this->patient('0812-1111-2222');
        $this->actingAs($patient->user);
        $data = ['doctor_id' => $this->doctor->doctor_id, 'polyclinic_id' => $this->doctor->polyclinic_id, 'date' => '2026-11-04'];

        $this->post('/kunjungan', $data)->assertRedirect('/kunjungan');
        $this->from('/dokter')->post('/kunjungan', $data)->assertRedirect('/dokter');
        $this->assertSame(1, $patient->queues()->count());
    }

    public function test_the_doctor_must_belong_to_the_chosen_polyclinic(): void
    {
        $other = Doctor::factory()->create();
        $this->actingAs($this->patient('0812-1111-2222')->user)->from('/dokter')->post('/kunjungan', [
            'doctor_id' => $this->doctor->doctor_id,
            'polyclinic_id' => $other->polyclinic_id,
            'date' => '2026-11-04',
        ])->assertRedirect('/dokter');
        $this->assertSame(0, Queue::count());
    }

    public function test_the_last_slot_goes_to_one_patient_only(): void
    {
        config(['hytrack.daily_quota' => 1]);
        $first = $this->patient('0812-1111-2222');
        $second = $this->patient('0812-1111-3333');
        $data = ['doctor_id' => $this->doctor->doctor_id, 'date' => '2026-11-04'];

        $this->actingAs($first->user)->post('/kunjungan', $data)->assertRedirect('/kunjungan');
        $this->actingAs($second->user)->from('/dokter')->post('/kunjungan', $data)->assertRedirect('/dokter');
        $this->assertSame(1, Queue::count());
        $this->props('/dokter?poli='.$this->doctor->polyclinic_id, fn (AssertableInertia $page) => $page
            ->where('doctors.0.days.2026-11-04.quota', 0));
    }

    public function test_no_show_is_only_recorded_after_the_check_in_deadline(): void
    {
        $queue = $this->bookFor($this->patient('0812-1111-2222'), '2026-11-04');
        $this->actingAs($this->staff->user);

        // 08.30: before the 08.45 deadline the patient is "Belum check-in", not absent.
        $this->travelTo(Carbon::parse('2026-11-04 08:30', 'Asia/Jakarta'));
        $this->assertStringContainsString('belum melewati batas check-in pukul 08.45', $this->postAndFlash("/petugas/antrean/{$queue->queue_id}/tidak-hadir")['error']);
        $this->assertSame('booked', $queue->refresh()->queue_status->value);

        $this->travelTo(Carbon::parse('2026-11-04 08:50', 'Asia/Jakarta'));
        $this->postAndFlash("/petugas/antrean/{$queue->queue_id}/tidak-hadir");
        $queue->refresh();
        $this->assertSame('no_show', $queue->queue_status->value);
        $this->assertSame($this->staff->staff_id, $queue->no_show_by_staff_id);
        $this->assertSame(StageStatus::Skipped, $queue->journeys()->where('stage_order', 1)->value('stage_status'));

        // The QR now identifies an unusable ticket.
        $this->assertSame('no_show', $this->postAndFlash('/petugas/scan', ['code' => CheckInTicket::payload($queue)])['scan']['state']);
        $this->assertStringContainsString('tidak hadir', $this->postAndFlash('/petugas/check-in', ['code' => CheckInTicket::payload($queue)])['error']);
    }

    public function test_staff_cancel_with_a_reason_frees_the_slot_and_voids_the_qr(): void
    {
        config(['hytrack.daily_quota' => 1]);
        $patient = $this->patient('0812-1111-2222');
        $queue = $this->bookFor($patient, '2026-11-04');
        $this->actingAs($this->staff->user);

        $this->from('/petugas')->post("/petugas/antrean/{$queue->queue_id}/batal", ['reason' => ''])
            ->assertSessionHasErrors(['reason' => 'Tuliskan alasan pembatalan.']);
        $this->postAndFlash("/petugas/antrean/{$queue->queue_id}/batal", ['reason' => 'Pasien minta jadwal ulang']);
        $queue->refresh();
        $this->assertSame('cancelled', $queue->queue_status->value);
        $this->assertSame('Pasien minta jadwal ulang', $queue->cancel_reason);
        $this->assertSame($this->staff->staff_id, $queue->cancelled_by_staff_id);
        $this->assertNotNull($queue->cancelled_at);
        $this->assertSame(1, Queue::count()); // kept for the audit trail, not deleted

        $this->assertStringStartsWith('Booking ini sudah dibatalkan (Pasien minta jadwal ulang)', $this->postAndFlash('/petugas/scan', ['code' => CheckInTicket::payload($queue)])['error']);

        // The patient sees why; the slot is free again and numbering continues.
        $this->props('/kunjungan', fn (AssertableInertia $page) => $page
            ->where('visit.stage', 'cancelled')->where('visit.cancelReason', 'Pasien minta jadwal ulang'), $patient->user);
        $other = $this->bookFor($this->patient('0812-1111-3333'), '2026-11-04');
        $this->assertSame('A-002', $other->queue_number);
    }

    public function test_staff_book_a_walk_in_patient_under_the_same_rules(): void
    {
        $patient = $this->patient('0812-1111-2222', 'Siti Aminah');
        $this->actingAs($this->staff->user);

        $this->getJson('/petugas/pasien?telepon=%2B62%20812-1111-2222')->assertOk()->assertJsonPath('patient.name', 'Siti Aminah');
        $this->getJson('/petugas/pasien?telepon=0899-0000-0000')->assertNotFound();
        $this->getJson('/petugas/jadwal?tanggal=2026-11-04')->assertJsonPath('clinics.0.doctors.0.quota', 30);

        $data = ['phone' => '0812 1111 2222', 'polyclinic_id' => $this->doctor->polyclinic_id, 'doctor_id' => $this->doctor->doctor_id, 'date' => '2026-11-04'];
        $this->assertStringStartsWith('Booking A-001 dibuat untuk Siti Aminah', $this->postAndFlash('/petugas/booking', $data)['status']);
        $queue = $patient->queues()->firstOrFail();
        $this->assertSame($this->staff->staff_id, $queue->booked_by_staff_id);
        $this->getJson('/petugas/jadwal?tanggal=2026-11-04')->assertJsonPath('clinics.0.doctors.0.quota', 29);

        // One active visit per patient, whoever books it.
        $this->assertSame('Pasien ini sudah memiliki satu kunjungan aktif.', $this->postAndFlash('/petugas/booking', $data)['error']);
        $this->assertSame('Nomor ponsel belum terdaftar sebagai akun pasien.', $this->postAndFlash('/petugas/booking', [...$data, 'phone' => '0899-0000-0000'])['error']);
    }

    public function test_bookings_can_be_searched_and_filtered(): void
    {
        $first = $this->bookFor($this->patient('0812-1111-2222', 'Siti Aminah'), '2026-11-04');
        $this->bookFor($this->patient('0812-1111-3333', 'Agus Pratama'), '2026-11-04');
        $this->actingAs($this->staff->user);
        $this->postAndFlash('/petugas/check-in', ['code' => $first->checkInCode()]);
        $this->assertSame('code', $first->refresh()->checkin_method);

        $this->props('/petugas', fn (AssertableInertia $page) => $page->where('bookingTotal', 2));
        $this->props('/petugas?cari=A-002', fn (AssertableInertia $page) => $page->where('bookingTotal', 1)->where('bookings.0.name', 'Agus Pratama'));
        $this->props('/petugas?cari='.$first->checkInCode(), fn (AssertableInertia $page) => $page->where('bookings.0.number', 'A-001'));
        $this->props('/petugas?status=waiting', fn (AssertableInertia $page) => $page->where('bookingTotal', 1)->where('bookings.0.checkInMethod', 'Nomor check-in'));
        $this->props('/petugas?bpoli=999', fn (AssertableInertia $page) => $page->where('bookingTotal', 0));
        $this->props('/petugas?tanggal=2026-11-05', fn (AssertableInertia $page) => $page->where('bookingTotal', 0));
        $this->props('/petugas?tanggal=', fn (AssertableInertia $page) => $page->where('bookingTotal', 2));
    }

    public function test_a_stale_booking_from_an_earlier_date_does_not_block_a_new_one(): void
    {
        $patient = $this->patient('0812-1111-2222');
        $this->bookFor($patient, '2026-11-04');
        $this->travelTo(Carbon::parse('2026-11-06 07:30', 'Asia/Jakarta'));

        $queue = $this->bookFor($patient, '2026-11-06');
        $this->assertSame('2026-11-06', $queue->service_date);
        $this->props('/riwayat', fn (AssertableInertia $page) => $page
            ->where('items.1.statusLabel', 'Tidak check-in'), $patient->user);
    }

    /** Patients (phone numbers) sign in at /masuk; staff accounts at /masuk/internal. */
    private function login(string $username, string $password)
    {
        return preg_match('/[a-z]/i', $username)
            ? $this->post('/masuk/internal', ['username' => $username, 'password' => $password, 'as' => 'staff'])
            : $this->post('/masuk', ['username' => $username, 'password' => $password]);
    }

    private function patient(string $phone, string $name = 'Pasien Uji'): Patient
    {
        $digits = Patient::normalizePhone($phone);

        return Patient::factory()->create([
            'full_name' => $name,
            'phone_number' => Patient::formatPhone($digits),
            'user_id' => User::factory()->create(['username' => $digits])->user_id,
        ]);
    }

    private function bookFor(Patient $patient, string $date): Queue
    {
        $this->actingAs($patient->user)->post('/kunjungan', ['doctor_id' => $this->doctor->doctor_id, 'date' => $date])
            ->assertRedirect('/kunjungan');

        return $patient->queues()->latest('queue_id')->firstOrFail();
    }

    private function props(string $url, callable $assert, ?User $as = null): void
    {
        if ($as) {
            $this->actingAs($as);
        }
        $this->get($url)->assertOk()->assertInertia($assert);
    }

    /** POSTs from the page and returns the flash data the page receives afterwards. */
    private function postAndFlash(string $url, array $data = []): array
    {
        $this->from('/petugas')->post($url, $data)->assertRedirect('/petugas');
        $flash = [];
        $this->get('/petugas')->assertInertia(function (AssertableInertia $page) use (&$flash) {
            $flash = $page->toArray()['flash'];
        });

        return $flash;
    }
}

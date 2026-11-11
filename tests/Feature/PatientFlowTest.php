<?php

namespace Tests\Feature;

use App\Models\CompanionShare;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Polyclinic;
use App\Models\Queue;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PatientFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-04';

    private User $user;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TODAY.' 08:00', 'Asia/Jakarta'));
        $this->user = Patient::factory()->create(['full_name' => 'Siti Aminah', 'phone_number' => '+62 812-5550-0842'])->user;
        $this->doctor = Doctor::factory()->create(['doctor_name' => 'dr. Rina Kusuma, Sp.PD']);
        $this->actingAs($this->user);
    }

    private function book(): Queue
    {
        $this->post('/kunjungan', ['doctor_id' => $this->doctor->doctor_id, 'date' => self::TODAY])
            ->assertRedirect('/kunjungan');

        return $this->user->patient->queues()->latest('queue_id')->firstOrFail();
    }

    private function simulate(string $action)
    {
        return $this->from('/kunjungan')->post('/kunjungan/simulasi', ['action' => $action]);
    }

    public function test_clinics_and_doctors_come_from_the_database(): void
    {
        Polyclinic::factory()->create(['polyclinic_name' => 'Saraf', 'room_location' => null]);

        $this->get('/poliklinik')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Clinics')
            ->where('clinics.0.name', 'Penyakit Dalam')
            ->where('clinics.0.doctors', 1)
            ->where('clinics.0.wait', '0–10')
            ->where('clinics.1.doctors', 0));

        $this->get('/dokter?poli='.$this->doctor->polyclinic_id)->assertInertia(fn (AssertableInertia $page) => $page
            ->component('DoctorSchedule')
            ->where('days.0.date', self::TODAY)
            ->where('days.0.label', '4 Nov')
            ->where('days.0.weekday', 'Rabu')
            ->has('days', 3)
            ->where('doctors.0.time', '09.00–12.00')
            ->where('doctors.0.days.'.self::TODAY.'.quota', 30));
    }

    public function test_booking_shows_the_ticket_derived_from_the_chosen_doctor(): void
    {
        $this->book();

        $this->get('/kunjungan')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Visit')
            ->where('visit.ticket', 'A-001')
            ->where('visit.stage', 'booked')
            ->where('visit.doctor', 'dr. Rina Kusuma, Sp.PD')
            ->where('visit.dateLabel', 'Rabu, 4 November 2026')
            ->where('visit.time', '09.00–12.00')
            ->where('visit.checkInBy', '08.45')
            ->where('visit.leaveBy', '08.20')
            ->where('visit.checkInCode', 'HT-000001')
            ->where('visit.companionActive', false));

        // A second active visit is refused with a message, not an error page.
        $this->from('/dokter')
            ->post('/kunjungan', ['doctor_id' => $this->doctor->doctor_id, 'date' => self::TODAY])
            ->assertRedirect('/dokter');
        $this->get('/dokter')->assertInertia(fn (AssertableInertia $page) => $page
            ->hasFlash('error', 'Anda sudah memiliki satu kunjungan aktif.'));
        $this->assertSame(1, Queue::count());
    }

    public function test_booking_input_is_validated(): void
    {
        $this->post('/kunjungan', ['doctor_id' => 999, 'date' => 'besok'])
            ->assertSessionHasErrors(['doctor_id', 'date']);
        $this->assertSame(0, Queue::count());
    }

    public function test_demo_controls_walk_the_visit_to_completion(): void
    {
        config(['hytrack.demo_controls' => true]);
        $queue = $this->book();

        $stages = ['check-in' => 'waiting', 'examined' => 'laboratory', 'laboratory' => 'pharmacy'];
        foreach ($stages as $action => $stage) {
            $this->simulate($action)->assertRedirect('/kunjungan');
            $this->assertSame($stage, $queue->refresh()->queue_status->value);
        }

        // Medicine cannot be handed over before it is ready.
        $this->simulate('picked-up');
        $this->assertSame('pharmacy', $queue->refresh()->queue_status->value);

        $this->simulate('medicine-ready');
        $this->get('/kunjungan')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('visit.pharmacy.status', 'ready')
            ->where('visit.pharmacy.pickupNumber', 'F-001'));

        $this->simulate('picked-up');
        $this->get('/kunjungan')->assertInertia(fn (AssertableInertia $page) => $page->where('visit.stage', 'completed'));
        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->component('Home'));
    }

    public function test_patients_cannot_advance_their_own_visit_outside_demo_mode(): void
    {
        $queue = $this->book();

        $this->simulate('check-in')->assertForbidden();
        $this->assertSame('booked', $queue->refresh()->queue_status->value);
    }

    public function test_a_patient_only_ever_sees_their_own_visit(): void
    {
        $this->book();
        $other = Patient::factory()->create();
        app(QueueService::class)->book($other, $this->doctor, self::TODAY);

        $this->actingAs($other->user)->get('/kunjungan')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('visit.ticket', 'A-002')
            ->where('visit.patientName', $other->full_name));
    }

    public function test_cancelling_frees_the_patient_for_a_new_booking(): void
    {
        $queue = $this->book();

        $this->from('/kunjungan')->delete('/kunjungan')->assertRedirect('/kunjungan');

        $this->assertSame('cancelled', $queue->refresh()->queue_status->value);
        $this->get('/kunjungan')->assertInertia(fn (AssertableInertia $page) => $page->where('visit', null));
        $this->assertSame('A-002', $this->book()->queue_number);
    }

    public function test_companion_link_is_hashed_limited_and_revocable(): void
    {
        $queue = $this->book();

        $this->from('/pendamping')->post('/pendamping')->assertRedirect('/pendamping');
        $link = null;
        $this->get('/pendamping')->assertInertia(function (AssertableInertia $page) use (&$link) {
            $link = $page->toArray()['flash']['shareLink'];
            $page->where('visit.companionActive', true);
        });
        $token = basename($link);

        // Only the hash is stored.
        $share = CompanionShare::sole();
        $this->assertSame(hash('sha256', $token), $share->access_token_hash);
        $this->assertStringNotContainsString($token, json_encode($share->getAttributes()));
        $this->assertSame('2026-11-04 16:59:59', $share->expires_at->toDateTimeString()); // 23.59 WIB in UTC

        // The companion needs no account and sees progress only.
        auth()->logout();
        $response = $this->get('/pantauan/'.$token)->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('CompanionView')
            ->where('share.ticket', $queue->queue_number)
            ->where('share.patientName', 'Siti A.')
            ->missing('share.checkInCode')
            ->missing('share.doctor')
            ->where('visit', null));
        $this->assertStringNotContainsString('Aminah', $response->getContent());
        $this->assertStringNotContainsString('0842', $response->getContent());

        $this->get('/pantauan/token-yang-salah')->assertNotFound()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('share', null));

        // The patient revokes: the same link stops working.
        $this->actingAs($this->user)->from('/pendamping')->delete('/pendamping');
        auth()->logout();
        $this->get('/pantauan/'.$token)->assertNotFound();
    }

    public function test_companion_links_expire_and_can_be_dropped_by_the_companion(): void
    {
        $this->book();
        $this->post('/pendamping');
        $token = '';
        $this->get('/pendamping')->assertInertia(function (AssertableInertia $page) use (&$token) {
            $token = basename($page->toArray()['flash']['shareLink']);
        });
        auth()->logout();

        $this->delete('/pantauan/'.$token)->assertRedirect('/pantauan/'.$token);
        $this->get('/pantauan/'.$token)->assertNotFound();

        CompanionShare::query()->update(['is_active' => true]);
        $this->get('/pantauan/'.$token)->assertOk();
        $this->travelTo(Carbon::parse('2026-11-05 00:01', 'Asia/Jakarta'));
        $this->get('/pantauan/'.$token)->assertNotFound();
    }

    public function test_notifications_follow_queue_changes_and_are_marked_read(): void
    {
        config(['hytrack.demo_controls' => true]);
        $this->book();
        $this->simulate('check-in');

        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('unread', 2));
        $this->get('/notifikasi')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Notifications')
            ->has('items', 2)
            ->where('items.0.message', 'Check-in berhasil. Anda masuk antrean dokter.')
            ->where('items.0.unread', true));
        $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('unread', 0));
    }

    public function test_hya_answers_from_the_patients_real_queue(): void
    {
        $ask = fn (string $question) => $this->postJson('/hya', ['question' => $question])->assertOk()->json('reply');

        $this->assertStringContainsString('tenaga kesehatan', $ask('Berapa dosis obat?'));
        $this->assertStringContainsString('Belum ada kunjungan aktif', $ask('Kapan giliran saya?'));
        $this->assertStringContainsString('nomor check-in', $ask('Bagaimana cara check-in?'));

        config(['hytrack.demo_controls' => true]);
        $queue = $this->book();
        $this->assertStringContainsString('belum check-in', $ask('Kapan giliran saya?'));

        // Two patients arrive first, so Hya reports two people ahead.
        foreach (range(1, 2) as $ahead) {
            app(QueueService::class)->checkIn(app(QueueService::class)->book(Patient::factory()->create(), $this->doctor, self::TODAY));
            $this->travel(1)->minutes();
        }
        $this->simulate('check-in');
        $this->assertStringContainsString('Ada 2 pasien di depan Anda. Perkiraan menunggu 10–20 menit.', $ask('Kapan giliran saya?'));
        $this->assertStringContainsString('Ruang A-204', $ask('Ke ruangan mana saya harus pergi?'));

        $this->simulate('examined');
        $this->assertStringContainsString('Laboratorium A-108', $ask('Kapan giliran saya?'));
        $this->simulate('laboratory');
        $this->assertStringContainsString('F-001', $ask('Kapan giliran saya?'));
        $this->assertStringContainsString('Loket F2', $ask('Ke ruangan mana?'));

        $this->postJson('/hya', ['question' => str_repeat('a', 501)])->assertUnprocessable();
        $this->assertSame('pharmacy', $queue->refresh()->queue_status->value);
    }
}

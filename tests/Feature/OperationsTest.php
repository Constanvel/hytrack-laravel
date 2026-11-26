<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\Staff;
use App\Models\User;
use App\Services\QueueService;
use App\Services\ServiceInsights;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-04';

    private Doctor $doctor;

    private Staff $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TODAY.' 08:30', 'Asia/Jakarta'));
        $this->doctor = Doctor::factory()->create();
        $this->staff = Staff::factory()->create(['full_name' => 'Dina']);
    }

    private function booked(string $name): Queue
    {
        return app(QueueService::class)->book(Patient::factory()->create(['full_name' => $name]), $this->doctor, self::TODAY);
    }

    public function test_staff_run_the_queue_from_arrival_to_the_pharmacy(): void
    {
        $agus = $this->booked('Agus Setiawan');
        $rudi = $this->booked('Rudi Hartono');
        $this->actingAs($this->staff->user)->from('/petugas');

        $this->post('/petugas/check-in', ['code' => $agus->checkInCode()])->assertRedirect('/petugas');
        $this->travel(3)->minutes();
        $this->post('/petugas/check-in', ['code' => 'ht-'.$rudi->queue_id])->assertRedirect('/petugas');

        $this->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Staff')
            ->where('stats', ['waiting' => 2, 'examined' => 0, 'notCheckedIn' => 0, 'registered' => 2])
            ->where('queue.0.number', 'A-001')
            ->where('queue.0.name', 'Agus Setiawan')
            ->where('queue.0.checkIn', '08.30')
            ->where('current', null));

        $call = "/petugas/dokter/{$this->doctor->doctor_id}/panggil";
        $this->post($call);
        $this->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('current.number', 'A-001')
            ->where('current.calledCount', 1)
            ->has('queue', 1));

        // Calling again while a patient is with the doctor is refused with a reason.
        $this->post($call)->assertRedirect('/petugas');
        $this->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page
            ->hasFlash('error', 'Selesaikan pasien aktif sebelum memanggil berikutnya.'));

        // Absence needs a repeat call first.
        $this->post("/petugas/antrean/{$agus->queue_id}/tidak-hadir");
        $this->assertSame('serving', $agus->refresh()->queue_status->value);
        $this->post("/petugas/antrean/{$agus->queue_id}/panggil-ulang");
        $this->post("/petugas/antrean/{$agus->queue_id}/tidak-hadir");
        $this->assertSame('no_show', $agus->refresh()->queue_status->value);

        $this->post($call);
        $this->travel(6)->minutes();

        // Only the doctor of the visit confirms the examination and prescription.
        $exam = ['notes' => 'Demam tiga hari.', 'examined' => '1', 'items' => [
            ['name' => 'Parasetamol', 'form' => 'Tablet 500 mg', 'quantity' => '10 tablet', 'dosage' => '3 × 1 tablet', 'timing' => 'Sesudah makan'],
        ]];
        $this->post("/petugas/pemeriksaan/{$rudi->queue_id}/konfirmasi", $exam)->assertForbidden();
        $doctor = Staff::factory()->create(['full_name' => 'dr. Rina', 'doctor_id' => $this->doctor->doctor_id]);
        $this->actingAs($doctor->user)->post("/petugas/pemeriksaan/{$rudi->queue_id}/konfirmasi", $exam)->assertRedirect('/petugas');
        $order = $rudi->pharmacyOrder()->firstOrFail();

        // The desk sees the prescription's status, never its contents.
        $this->actingAs($this->staff->user)->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('stats.examined', 1)
            ->where('flow.visits.0.number', 'A-002')
            ->where('flow.visits.0.note', 'Resep diterima · belum diproses')
            ->where('pharmacy.0.pickup', 'F-001')
            ->where('pharmacy.0.statusLabel', 'Belum diproses')
            ->where('pharmacy.0.itemCount', 1)
            ->where('pharmacy.0.items', [])
            ->where('pharmacy.0.canAct', false)
            ->where('history', fn ($rows) => collect($rows)->contains('activity', 'Pemeriksaan dikonfirmasi dokter')));
        $this->post("/petugas/farmasi/{$order->pharmacy_order_id}/lanjut")->assertForbidden();

        // Pharmacy staff tick each step's checklist before moving the prescription on.
        $pharmacist = Staff::factory()->create(['full_name' => 'Apt. Dinda', 'work_unit' => 'Farmasi']);
        $this->actingAs($pharmacist->user)->from('/petugas');
        $this->post("/petugas/farmasi/{$order->pharmacy_order_id}/lanjut", ['checks' => ['identity']]);
        $this->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page
            ->hasFlash('error', 'Centang semua pemeriksaan sebelum melanjutkan.')
            ->where('pharmacy.0.items.0.name', 'Parasetamol')
            ->where('pharmacy.0.allergy', 'tidak dilaporkan'));
        foreach ([['identity', 'legible', 'dosage'], ['items', 'label', 'etiket']] as $checks) {
            $this->post("/petugas/farmasi/{$order->pharmacy_order_id}/lanjut", ['checks' => $checks]);
        }
        $this->assertSame('ready', $order->refresh()->prescription_status->value);
        $this->post("/petugas/farmasi/{$order->pharmacy_order_id}/panggil");
        $this->assertNotNull($order->refresh()->called_at);
        $this->post("/petugas/farmasi/{$order->pharmacy_order_id}/lanjut", ['checks' => ['identity', 'medicine', 'explain']]);

        $this->assertSame('completed', $rudi->refresh()->queue_status->value);
        $this->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pharmacy.0.status', 'completed')
            ->where('pharmacyStats.done', 1)
            ->where('history.0.activity', 'Obat diserahkan')
            ->where('history.0.staff', 'Apt. Dinda'));
    }

    public function test_unknown_check_in_numbers_are_reported(): void
    {
        $this->actingAs($this->staff->user)->from('/petugas');

        $this->post('/petugas/check-in', ['code' => 'HT-999999'])->assertRedirect('/petugas');
        $this->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page
            ->hasFlash('error', 'Nomor check-in tidak ditemukan.'));
        $this->post('/petugas/check-in', ['code' => ''])->assertSessionHasErrors('code');
    }

    public function test_insights_aggregate_waits_without_patient_identities(): void
    {
        $queues = app(QueueService::class);
        $slow = $this->booked('Agus Setiawan');
        $fast = $this->booked('Rudi Hartono');
        $late = $this->booked('Ratna Sari');

        $queues->checkIn($slow, $this->staff);                       // 08.30, before the 08.45 deadline
        $queues->checkIn($fast, $this->staff);
        $this->travel(10)->minutes();
        $queues->callNext($this->doctor, self::TODAY, $this->staff);  // Agus waited 10 minutes
        $queues->completeConsultation($slow, $this->staff);
        $this->travel(30)->minutes();
        $queues->checkIn($late, $this->staff);                       // 09.10, after the deadline
        $queues->callNext($this->doctor, self::TODAY, $this->staff);  // Rudi waited 40 minutes

        $report = app(ServiceInsights::class)->report(self::TODAY);

        $this->assertSame(3, $report['registered']);
        $this->assertSame(3, $report['active']);
        $this->assertSame(25, $report['averageWait']);
        $this->assertSame(50, $report['withinTarget']);
        $this->assertSame(67, $report['onTime']);
        $this->assertSame([['hour' => '08.00', 'wait' => 25]], $report['hourly']);
        $this->assertSame('Dokter → laboratorium', $report['stages'][1]['label']);
        $this->assertSame([['hour' => '08.00', 'wait' => 25]], $report['stages'][0]['hourly']);
        $this->assertSame('09.10', $report['updatedAt']);
        $this->assertSame('Penyakit Dalam', $report['clinics'][0]['clinic']);
        $this->assertSame(0, app(ServiceInsights::class)->report(self::TODAY, 999)['registered']);

        $manager = User::factory()->manager()->create();
        $page = $this->actingAs($manager)->get('/manajemen?tanggal='.self::TODAY);
        $page->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->component('Management')
            ->where('date', self::TODAY)
            ->where('report.averageWait', 25)
            ->where('dates.0.label', '4 Nov 2026'));
        $csv = $this->get('/manajemen/ekspor?tanggal='.self::TODAY)->assertOk()
            ->assertDownload('hytrack-laporan-2026-11-04.csv')
            ->streamedContent();

        $this->assertStringContainsString('"Penyakit Dalam",0,25,Sesuai', $csv);
        foreach ([$page->getContent(), $csv] as $output) {
            $this->assertStringNotContainsString('Agus', $output);
            $this->assertStringNotContainsString('A-001', $output);
        }

        $this->get('/manajemen?tanggal=kemarin')->assertSessionHasErrors('tanggal');
    }

    public function test_seeded_demo_data_reproduces_the_design_scenario(): void
    {
        $this->seed(DatabaseSeeder::class);
        $siti = User::where('username', 'siti')->firstOrFail();
        $rina = Doctor::where('doctor_name', 'dr. Rina Kusuma, Sp.PD')->firstOrFail();

        $this->post('/masuk', ['username' => 'siti', 'password' => config('hytrack.demo_password')])->assertRedirect('/');

        // Three patients wait behind A-023, so the next ticket is A-027.
        $ticket = app(QueueService::class)->book($siti->patient, $rina, self::TODAY);
        app(QueueService::class)->checkIn($ticket);

        $this->get('/kunjungan')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('visit.ticket', 'A-027')
            ->where('visit.ahead', 3)
            ->where('visit.waitRange', '15–25')
            ->where('visit.serving', 'A-023'));

        $this->actingAs(User::where('username', 'dina')->firstOrFail())
            ->get("/petugas?poli={$rina->polyclinic_id}&dokter={$rina->doctor_id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('stats', ['waiting' => 4, 'examined' => 19, 'notCheckedIn' => 0, 'registered' => 27])
                ->where('current.name', 'Hendra Wijaya')
                ->where('flow.visits', fn ($visits) => collect($visits)->where('stage', 'support')->count() === 3)
                ->where('pharmacy', fn ($orders) => collect($orders)->where('status', '!=', 'completed')->count() === 4));
    }
}

<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\Staff;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Figma 47:2 frames 31–37: doctor confirmation, support services and the pharmacy return path. */
class ClinicalFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-04';

    private Doctor $doctor;

    private Staff $desk;

    private Staff $physician;

    private Staff $analyst;

    private Staff $pharmacist;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('hytrack.support_result_disk'));
        $this->travelTo(Carbon::parse(self::TODAY.' 08:30', 'Asia/Jakarta'));
        $this->doctor = Doctor::factory()->create();
        $this->desk = Staff::factory()->create(['full_name' => 'Dina']);
        $this->physician = Staff::factory()->create(['full_name' => 'dr. Rina', 'doctor_id' => $this->doctor->doctor_id]);
        $this->analyst = Staff::factory()->create(['full_name' => 'Rani', 'work_unit' => 'Laboratorium']);
        $this->pharmacist = Staff::factory()->create(['full_name' => 'Apt. Dinda', 'work_unit' => 'Farmasi']);
    }

    /** A checked-in patient who is now with the doctor. */
    private function serving(string $name = 'Rudi Hartono'): Queue
    {
        $queues = app(QueueService::class);
        $queue = $queues->book(Patient::factory()->create(['full_name' => $name]), $this->doctor, self::TODAY);
        $queues->checkIn($queue, $this->desk);
        $queues->callNext($this->doctor, self::TODAY, $this->desk);

        return $queue->refresh();
    }

    private function exam(array $extra = []): array
    {
        return ['notes' => 'Nyeri ulu hati.', 'allergy' => 'Penisilin', 'pharmacy_note' => 'Minum sesudah makan.', 'items' => [
            ['name' => 'Omeprazol', 'form' => 'Kapsul 20 mg', 'quantity' => '14 kapsul', 'dosage' => '1 × 1 kapsul', 'timing' => 'Sebelum makan'],
        ], ...$extra];
    }

    public function test_referral_result_returns_to_the_doctor_who_then_confirms(): void
    {
        $queue = $this->serving();
        $this->actingAs($this->physician->user)->from('/petugas');

        $this->post("/petugas/pemeriksaan/{$queue->queue_id}/penunjang", $this->exam(['unit' => 'radiologi', 'test' => 'X']))
            ->assertSessionHasErrors('test');
        $this->post("/petugas/pemeriksaan/{$queue->queue_id}/penunjang", $this->exam(['unit' => 'laboratorium', 'test' => 'Darah lengkap']))
            ->assertRedirect('/petugas');
        $support = $queue->refresh()->supportRequest;
        $this->assertSame('laboratory', $queue->queue_status->value);
        $this->assertSame('PNJ-'.sprintf('%03d', $support->support_request_id), $support->number());

        // The doctor cannot confirm before the result is back.
        $this->post("/petugas/pemeriksaan/{$queue->queue_id}/konfirmasi", $this->exam(['examined' => '1']));
        $this->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page
            ->hasFlash('error', 'Tunggu hasil penunjang sebelum mengonfirmasi pemeriksaan.'));

        // Support staff: only status and result, never the doctor's notes or prescription.
        $this->actingAs($this->analyst->user);
        $this->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('me.room', 'support')
            ->where('support.0.test', 'Darah lengkap')
            ->where('support.0.canEdit', true)
            ->where('examinations.0.notes', null)
            ->where('examinations.0.items', []));
        $this->post("/petugas/penunjang/{$support->support_request_id}/selesai");
        $this->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page
            ->hasFlash('error', 'Lampirkan hasil pemeriksaan sebelum mengirim ke dokter.'));
        $this->post("/petugas/penunjang/{$support->support_request_id}", ['status' => 'processing', 'result' => UploadedFile::fake()->create('virus.exe', 10)])
            ->assertSessionHasErrors('result');
        $this->post("/petugas/penunjang/{$support->support_request_id}", [
            'status' => 'processing', 'note' => 'Sampel diterima.',
            'result' => UploadedFile::fake()->create('hasil-lab.pdf', 120, 'application/pdf'),
        ])->assertRedirect('/petugas');
        $this->post("/petugas/penunjang/{$support->support_request_id}/selesai")->assertRedirect('/petugas');
        $this->assertSame('done', $support->refresh()->status->value);

        // The file is private: the desk is refused, the referring doctor may download it.
        $this->actingAs($this->desk->user)->get("/petugas/penunjang/{$support->support_request_id}/hasil")->assertForbidden();
        $this->actingAs($this->physician->user)->get("/petugas/penunjang/{$support->support_request_id}/hasil")
            ->assertOk()->assertDownload('hasil-lab.pdf')->assertHeader('Cache-Control', 'no-store, private');

        $this->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('examinations.0.state', 'review')
            ->where('examinations.0.notes', 'Nyeri ulu hati.')
            ->where('flow.visits.0.note', 'Hasil penunjang · menunggu dokter'));
        $this->post("/petugas/pemeriksaan/{$queue->queue_id}/konfirmasi", $this->exam())->assertSessionHasErrors('examined');
        $this->post("/petugas/pemeriksaan/{$queue->queue_id}/konfirmasi", $this->exam(['examined' => '1']))->assertRedirect('/petugas');
        $this->assertSame('pharmacy', $queue->refresh()->queue_status->value);
        $this->assertSame('Penisilin', $queue->examination->allergy_note);
    }

    public function test_pharmacy_returns_a_prescription_and_the_doctor_resends_it(): void
    {
        $queue = $this->serving();
        $this->actingAs($this->physician->user)->from('/petugas')
            ->post("/petugas/pemeriksaan/{$queue->queue_id}/konfirmasi", $this->exam(['examined' => '1']));
        $order = $queue->refresh()->pharmacyOrder;

        $this->actingAs($this->pharmacist->user);
        $this->post("/petugas/farmasi/{$order->pharmacy_order_id}/kembalikan", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/petugas/farmasi/{$order->pharmacy_order_id}/kembalikan", ['reason' => 'Dosis perlu dicek.'])->assertRedirect('/petugas');
        $this->assertSame('returned', $order->refresh()->prescription_status->value);
        $this->assertNull($queue->examination()->first()->confirmed_at);

        $this->actingAs($this->physician->user)->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('examinations.0.state', 'returned')
            ->where('examinations.0.order.returnReason', 'Dosis perlu dicek.'));
        $this->post("/petugas/pemeriksaan/{$queue->queue_id}/konfirmasi", $this->exam(['examined' => '1']));
        $this->assertSame('received', $order->refresh()->prescription_status->value);
    }

    public function test_a_visit_without_medicine_ends_at_confirmation(): void
    {
        $queue = $this->serving();
        $this->actingAs($this->physician->user)->from('/petugas')
            ->post("/petugas/pemeriksaan/{$queue->queue_id}/konfirmasi", $this->exam(['items' => [], 'examined' => '1']));

        $this->assertSame('completed', $queue->refresh()->queue_status->value);
        $this->assertNull($queue->pharmacyOrder);
    }

    public function test_only_the_treating_doctor_examines(): void
    {
        $queue = $this->serving();
        $other = Staff::factory()->create(['doctor_id' => Doctor::factory()->create()->doctor_id]);

        $this->actingAs($other->user)->post("/petugas/pemeriksaan/{$queue->queue_id}", $this->exam())->assertForbidden();
        $this->actingAs($this->analyst->user)->post("/petugas/pemeriksaan/{$queue->queue_id}", $this->exam())->assertForbidden();
        $this->actingAs($this->pharmacist->user)->post("/petugas/pemeriksaan/{$queue->queue_id}", $this->exam())->assertForbidden();
        $this->assertNull($queue->examination()->first());

        $this->actingAs($this->physician->user)->post("/petugas/pemeriksaan/{$queue->queue_id}", $this->exam())->assertRedirect();
        $this->assertSame('Nyeri ulu hati.', $queue->examination()->first()->notes);
        // Another doctor sees that the visit is being examined, not what was written.
        $this->actingAs($other->user)->get('/petugas')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('examinations', fn ($rows) => collect($rows)->every(fn ($row) => $row['notes'] === null)));
    }

    public function test_internal_sign_in_checks_the_chosen_role(): void
    {
        $manager = User::factory()->manager()->create();
        $patient = Patient::factory()->create()->user;

        $this->get('/masuk/internal')->assertInertia(fn (AssertableInertia $page) => $page->component('InternalLogin'));
        $this->post('/masuk/internal', ['as' => 'manager', 'username' => $this->desk->user->username, 'password' => 'password'])
            ->assertSessionHasErrors(['as' => 'Akun ini terdaftar sebagai Petugas. Pilih peran Petugas.']);
        $this->assertGuest();
        $this->post('/masuk/internal', ['as' => 'staff', 'username' => $patient->username, 'password' => 'password'])
            ->assertSessionHasErrors('as');
        $this->assertGuest();
        $this->post('/masuk/internal', ['as' => 'staff', 'username' => $this->desk->user->username, 'password' => 'salah'])
            ->assertSessionHasErrors('username');
        $this->post('/masuk/internal', ['as' => 'manager', 'username' => $manager->username, 'password' => 'password'])
            ->assertRedirect('/manajemen');
        $this->assertAuthenticatedAs($manager);
    }

    public function test_the_api_runs_the_same_clinical_rules(): void
    {
        $queue = $this->serving();
        $login = fn (Staff $staff, ?string $as = 'staff') => $this->postJson('/api/masuk', ['username' => $staff->user->username, 'password' => 'password', 'as' => $as]);

        $login($this->desk, 'manager')->assertUnprocessable()->assertJsonValidationErrors('as');
        $token = $login($this->physician)->assertOk()->assertJsonPath('account.room', 'doctor')->json('token');

        $this->withToken($token)->getJson('/api/petugas')->assertOk()
            ->assertJsonPath('me.room', 'doctor')
            ->assertJsonPath('examinations.0.number', $queue->queue_number);
        $this->withToken($token)->postJson("/api/petugas/pemeriksaan/{$queue->queue_id}/konfirmasi", $this->exam())
            ->assertUnprocessable()->assertJsonValidationErrors('examined');
        $this->withToken($token)->postJson("/api/petugas/pemeriksaan/{$queue->queue_id}/konfirmasi", $this->exam(['examined' => true]))
            ->assertOk()->assertJsonPath('message', fn (string $message) => str_contains($message, 'dikirim ke farmasi'));

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $manager = User::factory()->manager()->create();
        $token = $this->postJson('/api/masuk', ['username' => $manager->username, 'password' => 'password', 'as' => 'manager'])->json('token');
        $this->withToken($token)->getJson('/api/manajemen')->assertOk()->assertJsonPath('report.registered', 1);
        $this->withToken($token)->getJson('/api/petugas')->assertForbidden();
    }
}

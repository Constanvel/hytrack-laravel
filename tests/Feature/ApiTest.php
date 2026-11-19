<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Staff;
use App\Services\QueueService;
use App\Support\CheckInTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** The Android app's API runs the same rules as the web screens. */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-04 08:00', 'Asia/Jakarta'));
    }

    private function token(string $username): string
    {
        return $this->as(null)->postJson('/api/masuk', ['username' => $username, 'password' => 'password'])
            ->assertOk()->json('token');
    }

    /**
     * Requests with this bearer token. Guards keep their user between requests of one test
     * process (unlike real requests), so they are reset whenever the token changes.
     */
    private function as(?string $token): static
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $token ? $this->withToken($token) : $this;
    }

    public function test_tokens_sign_in_and_out(): void
    {
        $patient = Patient::factory()->create(['full_name' => 'Siti Aminah', 'phone_number' => '+62 812-5550-0842']);

        $this->getJson('/api/akun')->assertUnauthorized();
        $this->postJson('/api/masuk', ['username' => '0812-5550-0842', 'password' => 'salah'])->assertUnprocessable();

        $token = $this->token('0812 5550 0842');
        $this->assertSame(hash('sha256', $token), $patient->user->refresh()->api_token_hash);
        $this->as($token)->getJson('/api/akun')->assertOk()
            ->assertJsonPath('account.name', 'Siti Aminah')->assertJsonPath('visit', null)
            ->assertJsonMissingPath('account.api_token_hash');

        $this->as($token)->postJson('/api/keluar')->assertOk();
        $this->as($token)->getJson('/api/akun')->assertUnauthorized();
    }

    public function test_patient_data_and_the_companion_flow_over_the_api(): void
    {
        $siti = Patient::factory()->create(['full_name' => 'Siti Aminah', 'phone_number' => '+62 812-5550-0842']);
        $ahmad = Patient::factory()->create(['full_name' => 'Ahmad Fauzi']);
        $queue = app(QueueService::class)->book($siti, Doctor::factory()->create(), '2026-11-04');
        $sitiToken = $this->token($siti->user->username);

        $this->as($sitiToken)->postJson('/api/data-pasien', [
            'name' => 'Rafi Pratama', 'birth_date' => '2015-03-09', 'gender' => 'male',
            'phone' => '081255500842', 'address' => 'Jl. Melati 12', 'city' => 'Bandung',
        ])->assertCreated()->assertJsonPath('patient.self', false);
        $this->as($sitiToken)->postJson('/api/data-pasien', ['name' => ''])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'birth_date', 'gender', 'phone', 'address', 'city']);
        $this->as($sitiToken)->getJson('/api/data-pasien')->assertJsonCount(2, 'patients');
        $this->as($sitiToken)->getJson('/api/akun')->assertJsonPath('visit.checkInQr', CheckInTicket::payload($queue));

        $invite = $this->as($sitiToken)->postJson('/api/pendamping')->assertCreated();
        $this->assertStringEndsWith('/pantauan/'.$invite->json('token'), $invite->json('link'));

        $ahmadToken = $this->token($ahmad->user->username);
        $this->as($sitiToken)->getJson('/api/pantauan/'.$invite->json('token'))->assertJsonPath('canAccept', false);
        $this->as($ahmadToken)->getJson('/api/pantauan/'.$invite->json('token'))->assertOk()
            ->assertJsonPath('invite.patientName', 'Siti A.')->assertJsonPath('canAccept', true);
        $id = $this->as($ahmadToken)->postJson('/api/pantauan/'.$invite->json('token'))->assertCreated()->json('id');
        $this->as($ahmadToken)->getJson('/api/pantauan/'.$invite->json('token'))->assertNotFound();
        $this->as($ahmadToken)->postJson('/api/pantauan/'.$invite->json('token'))->assertNotFound();
        $this->as($ahmadToken)->getJson('/api/pendampingan')->assertJsonPath('patients.0.name', 'Siti A.');
        $this->as($ahmadToken)->getJson("/api/pendampingan/{$id}")->assertOk()
            ->assertJsonPath('status', 'accepted')->assertJsonPath('progress.ticket', $queue->queue_number)
            ->assertJsonMissingPath('progress.checkInCode');

        $this->as($sitiToken)->getJson('/api/pendamping')->assertJsonPath('invitations.0.companion', 'Ahmad F.');
        $this->as($sitiToken)->deleteJson("/api/pendamping/{$id}")->assertOk();
        $this->as($ahmadToken)->getJson("/api/pendampingan/{$id}")
            ->assertJsonPath('status', 'revoked')->assertJsonPath('progress', null);
    }

    public function test_staff_scan_and_check_in_over_the_api(): void
    {
        $queue = app(QueueService::class)->book(Patient::factory()->create(), Doctor::factory()->create(), '2026-11-04');
        $staff = Staff::factory()->create();
        $token = $this->token($staff->user->username);
        $payload = CheckInTicket::payload($queue);

        $this->as($token)->postJson('/api/petugas/scan', ['code' => $payload])->assertOk()
            ->assertJsonPath('ticket.ticket', $queue->queue_number);
        $this->as($token)->postJson('/api/petugas/check-in', ['code' => $payload])->assertOk();
        // A second scan shows the ticket as already checked in; a second check-in is refused.
        $this->as($token)->postJson('/api/petugas/scan', ['code' => $payload])->assertOk()
            ->assertJsonPath('ticket.state', 'checked_in')
            ->assertJsonPath('ticket.checkedInBy', $staff->full_name);
        $this->as($token)->postJson('/api/petugas/check-in', ['code' => $payload])->assertUnprocessable()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'sudah check-in'));
        $this->assertSame('qr', $queue->refresh()->checkin_method);

        $patientToken = $this->token($queue->patient->user->username);
        $this->as($patientToken)->postJson('/api/petugas/scan', ['code' => $payload])->assertForbidden();
    }
}
